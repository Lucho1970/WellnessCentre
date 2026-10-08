param(
    [Parameter(Mandatory=$true)][string]$ReleaseName,
    [ValidateSet('PORTAL_HOST_CUTOVER.md','PRACTITIONER_PERSON_CARD.md','CLIENT_OVERVIEW_RELEASE.md','APPOINTMENT_ACTION_LINKS_RELEASE.md','RECURRING_APPOINTMENTS_RELEASE.md','CLIENT_INTAKE_FORMS_RELEASE.md','GOOGLE_PRACTITIONER_ROLLOUT.md','PRIVATE_PRACTITIONER_WORK_LOCATION.md')][string]$DeploymentGuide = 'PORTAL_HOST_CUTOVER.md'
)
$ErrorActionPreference = 'Stop'
if ($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$') { throw 'Use letters, digits, underscores, and hyphens for ReleaseName.' }
$repo = Split-Path $PSScriptRoot -Parent
$destination = Join-Path $repo "deployments/$ReleaseName"
if (Test-Path -LiteralPath $destination) { throw "Release already exists: $destination" }
$stage = Join-Path $repo ('.tmp/deployment-' + [guid]::NewGuid().ToString('N'))
$private = Join-Path $stage 'private'
New-Item -ItemType Directory -Path $private -Force | Out-Null
$productionSettings = @{}
foreach ($productionEnv in @((Join-Path $repo 'Frontend/.env.production'),(Join-Path $repo 'Frontend/.env.production.local'))) {
    if (-not (Test-Path -LiteralPath $productionEnv)) { continue }
    foreach ($line in Get-Content -LiteralPath $productionEnv) {
        if ($line -match '^\s*([^#][^=]*)=(.*)$') {
            $productionSettings[$matches[1].Trim()] = $matches[2].Trim()
        }
    }
}
$requiredFrontendSettings = @(
    'VITE_CUSTOMER_ENTRA_TENANT_ID',
    'VITE_CUSTOMER_ENTRA_SUBDOMAIN',
    'VITE_CUSTOMER_ENTRA_API_CLIENT_ID',
    'VITE_CUSTOMER_ENTRA_SPA_CLIENT_ID',
    'VITE_GOOGLE_MAPS_BROWSER_API_KEY'
)
$staffInvitationFlag = [Environment]::GetEnvironmentVariable('VITE_STAFF_INVITATIONS_ENABLED', 'Process')
if ([string]::IsNullOrWhiteSpace($staffInvitationFlag)) { $staffInvitationFlag = $productionSettings['VITE_STAFF_INVITATIONS_ENABLED'] }
if ($staffInvitationFlag -eq 'true') {
    $requiredFrontendSettings += @('VITE_STAFF_EXTERNAL_TENANT_ID','VITE_STAFF_EXTERNAL_SUBDOMAIN','VITE_STAFF_EXTERNAL_API_CLIENT_ID','VITE_STAFF_EXTERNAL_SPA_CLIENT_ID')
}
$resolvedFrontendSettings = @{}
foreach ($name in $requiredFrontendSettings) {
    $value = [Environment]::GetEnvironmentVariable($name, 'Process')
    if ([string]::IsNullOrWhiteSpace($value)) { $value = $productionSettings[$name] }
    if ([string]::IsNullOrWhiteSpace($value)) { throw "Missing required production frontend setting: $name" }
    $resolvedFrontendSettings[$name] = $value
}
$previousReleaseName = [Environment]::GetEnvironmentVariable('VITE_RELEASE_NAME', 'Process')
[Environment]::SetEnvironmentVariable('VITE_RELEASE_NAME', $ReleaseName, 'Process')
Push-Location (Join-Path $repo 'Frontend')
try { npm run build; if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed' } } finally {
    Pop-Location
    [Environment]::SetEnvironmentVariable('VITE_RELEASE_NAME', $previousReleaseName, 'Process')
}
$portalScripts = Get-ChildItem -LiteralPath (Join-Path $repo 'Frontend/dist/portal/assets') -Filter '*.js' -File
foreach ($name in $requiredFrontendSettings) {
    $expected = $resolvedFrontendSettings[$name]
    $found = $false
    foreach ($script in $portalScripts) {
        if ([System.IO.File]::ReadAllText($script.FullName).Contains($expected)) { $found = $true; break }
    }
    if (-not $found) { throw "Production portal bundle is missing required setting: $name" }
}
Copy-Item -LiteralPath (Join-Path $repo 'api/composer.json'),(Join-Path $repo 'api/composer.lock') -Destination $private
Copy-Item -LiteralPath (Join-Path $repo 'api/src'),(Join-Path $repo 'api/bin') -Destination $private -Recurse
Push-Location $private
try { composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction; if ($LASTEXITCODE -ne 0) { throw 'Composer install failed' } } finally { Pop-Location }
New-Item -ItemType Directory -Path $destination | Out-Null
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
function Write-DeploymentZip([string]$Source,[string]$Target) {
    $root = (Resolve-Path -LiteralPath $Source).Path
    $archive = [System.IO.Compression.ZipFile]::Open($Target,[System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in Get-ChildItem -LiteralPath $root -File -Recurse -Force) {
            $relative = $file.FullName.Substring($root.Length + 1).Replace('\','/')
            if ($relative -match '(^|/)(\.git|\.github|\.env[^/]*)(/|$)') { continue }
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive,$file.FullName,$relative,[System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally { $archive.Dispose() }
}
Write-DeploymentZip (Join-Path $repo 'Frontend/dist/public') (Join-Path $destination 'wellness-public.zip')
$portalStage = Join-Path $stage 'portal'
Copy-Item -LiteralPath (Join-Path $repo 'Frontend/dist/portal') -Destination $portalStage -Recurse
Copy-Item -LiteralPath (Join-Path $repo 'hosting/netfirms/portal/.htaccess'),(Join-Path $repo 'hosting/netfirms/portal/share-preview.php'),(Join-Path $repo 'hosting/netfirms/portal/share-preview-lib.php') -Destination $portalStage -Force
# Both public entry points resolve to the same private application; no backend copy.
Copy-Item -LiteralPath (Join-Path $repo 'api/deploy/netfirms/public') -Destination (Join-Path $portalStage 'api') -Recurse
Write-DeploymentZip $portalStage (Join-Path $destination 'wellness-portal.zip')
Write-DeploymentZip (Join-Path $repo 'hosting/netfirms/portal-landing') (Join-Path $destination 'copihue-portal-landing.zip')
Write-DeploymentZip $private (Join-Path $destination 'wellness-api-private.zip')
Write-DeploymentZip (Join-Path $repo 'api/deploy/netfirms/public') (Join-Path $destination 'wellness-api-public.zip')
Write-DeploymentZip (Join-Path $repo 'hosting/netfirms/main-domain') (Join-Path $destination 'tuff-tar-mail-bridge.zip')
Copy-Item -LiteralPath (Join-Path $repo 'api/database/migrations') -Destination (Join-Path $destination 'sql-updates') -Recurse
Copy-Item -LiteralPath (Join-Path $repo 'api/database/maintenance') -Destination (Join-Path $destination 'sql-maintenance') -Recurse
Copy-Item -LiteralPath (Join-Path $repo 'documentation/PORTAL_HOST_CUTOVER.md') -Destination $destination
Copy-Item -LiteralPath (Join-Path $repo 'documentation/PRACTITIONER_PERSON_CARD.md') -Destination $destination
Copy-Item -LiteralPath (Join-Path $repo "documentation/$DeploymentGuide") -Destination $destination -Force
if ($DeploymentGuide -eq 'RECURRING_APPOINTMENTS_RELEASE.md') {
    Copy-Item -LiteralPath (Join-Path $repo 'documentation/RECURRING_APPOINTMENTS.md') -Destination $destination
}
if ($DeploymentGuide -eq 'CLIENT_INTAKE_FORMS_RELEASE.md') {
    Copy-Item -LiteralPath (Join-Path $repo 'documentation/CLIENT_INTAKE_FORMS.md') -Destination $destination
}
$commit = git -C $repo rev-parse HEAD
if ($LASTEXITCODE -ne 0) { throw 'Cannot resolve source commit' }
$manifest = [ordered]@{ release_name=$ReleaseName; source_commit=$commit; built_at_utc=[DateTime]::UtcNow.ToString('o'); layout='separate-public-clinic-portal-and-neutral-landing'; deployment_guide=$DeploymentGuide; archives=@() }
if ($staffInvitationFlag -eq 'true') {
    $manifest.staff_authentication = [ordered]@{
        enabled_in_portal=$true
        tenant_id=$resolvedFrontendSettings['VITE_STAFF_EXTERNAL_TENANT_ID']
        subdomain=$resolvedFrontendSettings['VITE_STAFF_EXTERNAL_SUBDOMAIN']
        api_client_id=$resolvedFrontendSettings['VITE_STAFF_EXTERNAL_API_CLIENT_ID']
        spa_client_id=$resolvedFrontendSettings['VITE_STAFF_EXTERNAL_SPA_CLIENT_ID']
    }
}
foreach ($file in Get-ChildItem -LiteralPath $destination -Filter '*.zip') {
    $archive = [System.IO.Compression.ZipFile]::OpenRead($file.FullName)
    try {
        if ($archive.Entries.Count -eq 0) { throw "Empty archive: $($file.Name)" }
        if (@($archive.Entries | Where-Object { $_.FullName -match '(^|/)(\.env[^/]*|\.git)(/|$)' }).Count -gt 0) { throw 'Unexpected private file in archive' }
        $manifest.archives += [ordered]@{ file=$file.Name; bytes=$file.Length; entries=$archive.Entries.Count; sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
    } finally { $archive.Dispose() }
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $destination 'manifest.json') -Encoding utf8
Write-Output "Deployment files: $destination"
