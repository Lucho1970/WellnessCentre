param([Parameter(Mandatory=$true)][string]$ReleaseName,[switch]$RequirePractitionerPilot)
$ErrorActionPreference='Stop'
$repo=Split-Path $PSScriptRoot -Parent
$guide='CURRENT_APP_RELEASE_2026-10-29.md'
if ($RequirePractitionerPilot) {
    foreach ($flag in @('VITE_PRACTITIONER_APPLICATIONS_ENABLED','VITE_STAFF_EXTERNAL_AUTH_ENABLED','VITE_PRACTITIONER_SOCIAL_SIGN_IN_ENABLED')) {
        if ([Environment]::GetEnvironmentVariable($flag,'Process') -ne 'true') { throw "Required practitioner pilot build flag is not enabled: $flag" }
    }
    foreach ($setting in @('VITE_STAFF_EXTERNAL_TENANT_ID','VITE_STAFF_EXTERNAL_SUBDOMAIN','VITE_STAFF_EXTERNAL_API_CLIENT_ID','VITE_STAFF_EXTERNAL_SPA_CLIENT_ID')) {
        if ([string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($setting,'Process'))) { throw "Required practitioner pilot build setting is missing: $setting" }
    }
}
# The established builder checks committed source, validates production settings,
# builds all clinic surfaces and installs the exact private Composer lock.
$previousScanDirectory=[Environment]::GetEnvironmentVariable('PHP_INI_SCAN_DIR','Process')
try {
    $zipEnabled=& php -r 'echo extension_loaded("zip") ? "yes" : "no";'
    if($zipEnabled -ne 'yes'){
        # Enable the already installed PHP zip extension for this build process
        # only, so Composer uses locked distribution archives rather than clones.
        $phpRoot=Split-Path (Get-Command php).Source -Parent
        $zipDll=Join-Path $phpRoot 'ext/php_zip.dll'
        if(-not (Test-Path -LiteralPath $zipDll)){throw 'The existing PHP runtime needs zip support for distribution installs.'}
        $scanDirectory=Join-Path $repo ('.tmp/build-php-'+[guid]::NewGuid().ToString('N'))
        New-Item -ItemType Directory -Path $scanDirectory | Out-Null
        ('extension="'+$zipDll.Replace('\','/')+'"') | Set-Content -LiteralPath (Join-Path $scanDirectory 'zip.ini') -Encoding utf8
        [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR',($previousScanDirectory+[IO.Path]::PathSeparator+$scanDirectory),'Process')
        if((& php -r 'echo extension_loaded("zip") ? "yes" : "no";') -ne 'yes'){throw 'Build-only zip extension did not load.'}
    }
    & (Join-Path $PSScriptRoot 'build-deployment.ps1') -ReleaseName $ReleaseName -DeploymentGuide $guide
} finally {[Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR',$previousScanDirectory,'Process')}
$destination=Join-Path $repo "deployments/$ReleaseName"
$previousWebsitePortal=[Environment]::GetEnvironmentVariable('VITE_PORTAL_URL','Process')
Push-Location (Join-Path $repo 'Wellnexa')
try {
    [Environment]::SetEnvironmentVariable('VITE_PORTAL_URL','https://wellnexa.copihue.ca/','Process')
    npm run build
    if($LASTEXITCODE -ne 0){throw 'Wellnexa production build failed.'}
} finally {
    Pop-Location
    [Environment]::SetEnvironmentVariable('VITE_PORTAL_URL',$previousWebsitePortal,'Process')
}
Add-Type -AssemblyName System.IO.Compression.FileSystem
$website=Join-Path $destination 'wellnexa-website.zip'
[IO.Compression.ZipFile]::CreateFromDirectory((Join-Path $repo 'Wellnexa/dist'),$website,[IO.Compression.CompressionLevel]::Optimal,$false)
$referenceNames=@('PRACTITIONER_PILOT_ADMIN.md','PRACTITIONER_PILOT_ADMIN_VALIDATION_2026-11-01.md','WELLNEXA_PRACTITIONER_SIGN_IN.md','WELLNEXA_PRACTITIONER_LIVE_TEST_2026-11-01.md','PILOT_SAFETY_CONTROLS.md','API_REQUEST_BODY_LIMITS.md','FRONTEND_QUALITY_GATES.md','COMPLIANCE_AUDIT_2026-10-29.md','ONTARIO_HEALTH_APPLICATION_STANDARD.md','PROVIDER_EVIDENCE.md','AUTOMATED_COMMUNICATIONS.md','PRACTITIONER_APPLICATIONS.md','BILLING_ADMINISTRATION.md','SERVICE_IMPORT.md','EMAIL_DELIVERY.md')
foreach($name in $referenceNames){
    Copy-Item -LiteralPath (Join-Path $repo "documentation/$name") -Destination $destination
}
# Preserve the normative standard's repository-relative documentation links too.
Copy-Item -LiteralPath (Join-Path $repo 'AGENTS.md') -Destination $destination
$referenceDirectory=Join-Path $destination 'documentation'
New-Item -ItemType Directory -Path $referenceDirectory | Out-Null
foreach($name in ($referenceNames+@($guide,'DEPLOYMENT_LAYOUT.md'))){Copy-Item -LiteralPath (Join-Path $repo "documentation/$name") -Destination $referenceDirectory}
Copy-Item -LiteralPath (Join-Path $repo "documentation/$guide") -Destination (Join-Path $destination 'README.md')
$manifestPath=Join-Path $destination 'manifest.json'
$manifest=Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$manifest | Add-Member -NotePropertyName source_branch -NotePropertyValue (git -C $repo branch --show-current)
$manifest | Add-Member -NotePropertyName release_status -NotePropertyValue 'NOT READY FOR RELEASE'
$manifest | Add-Member -NotePropertyName hosted_acceptance -NotePropertyValue 'Not performed; synthetic hosted rehearsal and accountable approvals remain required.'
$manifest | Add-Member -NotePropertyName migration_policy -NotePropertyValue 'Includes historical source migrations through 058. New practitioner pilot administration needs separately approved 058 after verified prerequisites 032/040/049; it does not require disabled 056/057. Apply only verified unapplied migrations; never replay the directory or maintenance scripts.'
$manifest | Add-Member -NotePropertyName private_configuration -NotePropertyValue 'Not included. Existing hosted .env, clinic database, grants and PILOT_* settings are not changed by packaging.'
$manifest | Add-Member -NotePropertyName runtimes -NotePropertyValue ([ordered]@{
    node=(& node --version); npm=(& npm --version); php=(& php -r 'echo PHP_VERSION;'); composer=((& composer --no-ansi --version) | Select-Object -First 1)
})
$locks=[ordered]@{}
foreach($path in @('Frontend/package-lock.json','Wellnexa/package-lock.json','api/composer.lock')){
    $locks[$path]=(Get-FileHash -LiteralPath (Join-Path $repo $path) -Algorithm SHA256).Hash.ToLowerInvariant()
}
$manifest | Add-Member -NotePropertyName lockfile_sha256 -NotePropertyValue $locks
$manifest.archives=@()
foreach($file in Get-ChildItem -LiteralPath $destination -File -Filter '*.zip'){
    $archive=[IO.Compression.ZipFile]::OpenRead($file.FullName)
    try {
        if($archive.Entries.Count -eq 0){throw "Empty archive: $($file.Name)"}
        if(@($archive.Entries | Where-Object {
            $fontMap=$file.Name -eq 'wellness-api-private.zip' -and $_.FullName -match '^vendor/dompdf/php-font-lib/maps/[a-zA-Z0-9_-]+\.map$'
            $_.FullName -match '(^|/)(\.env[^/]*|\.git|node_modules)(/|$)' -or ($_.FullName -match '\.map$' -and -not $fontMap)
        }).Count){throw "Unexpected private/development file: $($file.Name)"}
        $manifest.archives += [ordered]@{file=$file.Name;bytes=$file.Length;entries=$archive.Entries.Count;sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
    } finally {$archive.Dispose()}
}
$manifest.deployment_targets | Add-Member -NotePropertyName 'wellnexa-website.zip' -NotePropertyValue '/public_html/wellnexa.ca'
$manifest | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $manifestPath -Encoding utf8
$inventory=@(Get-ChildItem -LiteralPath $destination -Recurse -File | ForEach-Object {
    [ordered]@{file=$_.FullName.Substring($destination.Length+1).Replace('\','/');bytes=$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
})
$inventory | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $destination 'release-files.json') -Encoding utf8
Write-Output "Full deployment build: $destination"
