param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference = 'Stop'
if ($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$') { throw 'Use letters, digits, underscores, and hyphens for ReleaseName.' }
$repo = Split-Path $PSScriptRoot -Parent
$destination = Join-Path $repo "deployments/$ReleaseName"
if (Test-Path -LiteralPath $destination) { throw "Release already exists: $destination" }
$stage = Join-Path $repo ('.tmp/deployment-' + [guid]::NewGuid().ToString('N'))
$private = Join-Path $stage 'private'
New-Item -ItemType Directory -Path $private -Force | Out-Null
Push-Location (Join-Path $repo 'Frontend')
try { npm run build; if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed' } } finally { Pop-Location }
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
# Both public entry points resolve to the same private application; no backend copy.
Copy-Item -LiteralPath (Join-Path $repo 'api/deploy/netfirms/public') -Destination (Join-Path $portalStage 'api') -Recurse
Write-DeploymentZip $portalStage (Join-Path $destination 'wellness-portal.zip')
Write-DeploymentZip $private (Join-Path $destination 'wellness-api-private.zip')
Write-DeploymentZip (Join-Path $repo 'api/deploy/netfirms/public') (Join-Path $destination 'wellness-api-public.zip')
Copy-Item -LiteralPath (Join-Path $repo 'api/database/migrations') -Destination (Join-Path $destination 'sql-updates') -Recurse
$commit = git -C $repo rev-parse HEAD
if ($LASTEXITCODE -ne 0) { throw 'Cannot resolve source commit' }
$manifest = [ordered]@{ source_commit=$commit; built_at_utc=[DateTime]::UtcNow.ToString('o'); layout='separate-public-and-portal'; deployment_guide='documentation/PORTAL_SEPARATION.md'; archives=@() }
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
