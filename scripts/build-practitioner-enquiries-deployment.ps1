param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference='Stop'
$repo=Split-Path $PSScriptRoot -Parent
& (Join-Path $PSScriptRoot 'build-portal-only-deployment.ps1') -ReleaseName $ReleaseName
$destination=Join-Path $repo "deployments/$ReleaseName"
Push-Location (Join-Path $repo 'Frontend')
try { npm run build:application-admin; if($LASTEXITCODE -ne 0){throw 'Application admin build failed.'} } finally { Pop-Location }
$centralSettings=@{}
foreach($file in @('Frontend/.env.production','Frontend/.env.production.local')){
    $path=Join-Path $repo $file
    if(Test-Path -LiteralPath $path){foreach($line in Get-Content -LiteralPath $path){if($line -match '^\s*([^#][^=]*)=(.*)$'){$centralSettings[$matches[1].Trim()]=$matches[2].Trim()}}}
}
$centralScripts=Get-ChildItem -LiteralPath (Join-Path $repo 'Frontend/dist/application-admin/assets') -Filter '*.js' -File
foreach($name in @('VITE_ENTRA_TENANT_ID','VITE_ENTRA_API_CLIENT_ID','VITE_ENTRA_SPA_CLIENT_ID')){
    $value=[Environment]::GetEnvironmentVariable($name,'Process');if([string]::IsNullOrWhiteSpace($value)){$value=$centralSettings[$name]}
    if([string]::IsNullOrWhiteSpace($value) -or -not @($centralScripts | Where-Object {[IO.File]::ReadAllText($_.FullName).Contains($value)}).Count){throw "Application administration is missing production setting: $name"}
}
Push-Location (Join-Path $repo 'Wellnexa')
$previousPortal=[Environment]::GetEnvironmentVariable('VITE_PORTAL_URL','Process')
[Environment]::SetEnvironmentVariable('VITE_PORTAL_URL','https://wellnexa.copihue.ca/','Process')
try { npm run build; if($LASTEXITCODE -ne 0){throw 'Website build failed.'} } finally { Pop-Location; [Environment]::SetEnvironmentVariable('VITE_PORTAL_URL',$previousPortal,'Process') }
Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::CreateFromDirectory((Join-Path $repo 'Wellnexa/dist'),(Join-Path $destination 'wellnexa-website.zip'))
$stage=Join-Path $repo ('.tmp/enquiry-central-'+[guid]::NewGuid().ToString('N'))
Copy-Item -LiteralPath (Join-Path $repo 'hosting/netfirms/portal-landing') -Destination $stage -Recurse
$admin=Join-Path $stage 'admin'
Copy-Item -LiteralPath (Join-Path $repo 'Frontend/dist/application-admin') -Destination $admin -Recurse
Move-Item -LiteralPath (Join-Path $admin 'central.html') -Destination (Join-Path $admin 'index.html')
Copy-Item -LiteralPath (Join-Path $repo 'api/deploy/netfirms/public') -Destination (Join-Path $stage 'api') -Recurse
[IO.Compression.ZipFile]::CreateFromDirectory($stage,(Join-Path $destination 'copihue-portal-landing.zip'))
$patch=[IO.Compression.ZipFile]::Open((Join-Path $destination 'wellness-api-enquiries-patch.zip'),[IO.Compression.ZipArchiveMode]::Create)
try { foreach($file in @('src/Api.php','src/Http/Request.php','src/Service/PractitionerEnquiryService.php','src/Service/CatalogService.php')) { [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($patch,(Join-Path $repo "api/$file"),$file) | Out-Null } } finally { $patch.Dispose() }
Copy-Item -LiteralPath (Join-Path $repo 'api/database/migrations/047_practitioner_enquiries.sql') -Destination $destination
Copy-Item -LiteralPath (Join-Path $repo 'documentation/PRACTITIONER_ENQUIRIES_RELEASE.md') -Destination (Join-Path $destination 'README.md') -Force
$manifestPath=Join-Path $destination 'manifest.json'
$manifest=Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$manifest.private_api_update_required=$true;$manifest.database_update_required=$true
$packages=@('wellnexa-website.zip','wellness-common-portal.zip','copihue-portal-landing.zip','wellness-api-enquiries-patch.zip') | ForEach-Object {[ordered]@{file=$_;sha256=(Get-FileHash -LiteralPath (Join-Path $destination $_) -Algorithm SHA256).Hash.ToLowerInvariant()}}
$manifest | Add-Member -NotePropertyName packages -NotePropertyValue $packages
$manifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $manifestPath -Encoding utf8
Write-Output "Practitioner enquiries deployment: $destination"
