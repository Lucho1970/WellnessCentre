param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference='Stop'
$repo=Split-Path $PSScriptRoot -Parent
# Reuse production configuration, bootstrap checks and source revision capture.
& (Join-Path $PSScriptRoot 'build-portal-only-deployment.ps1') -ReleaseName $ReleaseName
$destination=Join-Path $repo "deployments/$ReleaseName"
Push-Location (Join-Path $repo 'Wellnexa')
$previousWebsitePortal=[Environment]::GetEnvironmentVariable('VITE_PORTAL_URL','Process')
[Environment]::SetEnvironmentVariable('VITE_PORTAL_URL','https://wellnexa.copihue.ca/','Process')
try { npm run build; if($LASTEXITCODE -ne 0){throw 'Wellnexa build failed.'} } finally {
    Pop-Location
    [Environment]::SetEnvironmentVariable('VITE_PORTAL_URL',$previousWebsitePortal,'Process')
}
Add-Type -AssemblyName System.IO.Compression.FileSystem
$website=Join-Path $destination 'wellnexa-website.zip'
[System.IO.Compression.ZipFile]::CreateFromDirectory((Join-Path $repo 'Wellnexa/dist'),$website,[System.IO.Compression.CompressionLevel]::Optimal,$false)
$patchFiles=@('CatalogService.php','PractitionerPublicProfileService.php','PublicSocialLinks.php')
$patch=Join-Path $destination 'wellness-api-social-links-patch.zip'
$zip=[System.IO.Compression.ZipFile]::Open($patch,[System.IO.Compression.ZipArchiveMode]::Create)
try { foreach($file in $patchFiles){ [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip,(Join-Path $repo "api/src/Service/$file"),"src/Service/$file") | Out-Null } } finally { $zip.Dispose() }
Copy-Item -LiteralPath (Join-Path $repo 'api/database/migrations/046_practitioner_social_links.sql') -Destination $destination
Copy-Item -LiteralPath (Join-Path $repo 'documentation/WELLNEXA_WEBSITE_DISCOVERY_RELEASE.md') -Destination (Join-Path $destination 'README.md') -Force
$manifestPath=Join-Path $destination 'manifest.json'
$manifest=Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$manifest.private_api_update_required=$true
$manifest.database_update_required=$true
$packages=@('wellness-common-portal.zip','wellnexa-website.zip','wellness-api-social-links-patch.zip') | ForEach-Object {
    [ordered]@{file=$_;sha256=(Get-FileHash -LiteralPath (Join-Path $destination $_) -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$manifest | Add-Member -NotePropertyName packages -NotePropertyValue $packages
$manifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $manifestPath -Encoding utf8
Write-Output "Wellnexa website/social profiles package: $destination"
