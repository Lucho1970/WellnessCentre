param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference='Stop'
if($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$'){throw 'Use letters, digits, underscores and hyphens for ReleaseName.'}
$repo=Split-Path $PSScriptRoot -Parent
if(@(git -C $repo status --porcelain --untracked-files=no).Count){throw 'Commit tracked source before packaging.'}
$destination=Join-Path $repo "deployments/$ReleaseName"
if(Test-Path -LiteralPath $destination){throw "Release already exists: $destination"}
$baseName="$ReleaseName-portal-build"
& (Join-Path $PSScriptRoot 'build-portal-only-deployment.ps1') -ReleaseName $baseName
$base=Join-Path $repo "deployments/$baseName"
$baseManifest=Get-Content -LiteralPath (Join-Path $base 'manifest.json') -Raw | ConvertFrom-Json
$stage=Join-Path $repo ('.tmp/practitioner-travel-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path (Join-Path $stage 'src/Service'),(Join-Path $destination 'sql') -Force | Out-Null
foreach($file in @('src/Api.php','src/Service/PractitionerTravelSettingsService.php')){
    Copy-Item -LiteralPath (Join-Path $repo "api/$file") -Destination (Join-Path $stage $file)
}
Add-Type -AssemblyName System.IO.Compression.FileSystem
$private=Join-Path $destination 'wellness-api-practitioner-travel-patch.zip'
[System.IO.Compression.ZipFile]::CreateFromDirectory($stage,$private,[System.IO.Compression.CompressionLevel]::Optimal,$false)
Copy-Item -LiteralPath (Join-Path $base 'wellness-common-portal.zip') -Destination $destination
Copy-Item -LiteralPath (Join-Path $repo 'documentation/PRACTITIONER_TRAVEL_SETTINGS_RELEASE.md') -Destination (Join-Path $destination 'README.md')
Copy-Item -LiteralPath (Join-Path $repo 'documentation/DEPLOYMENT_LAYOUT.md') -Destination $destination
Copy-Item -LiteralPath (Join-Path $repo 'api/database/maintenance/onsite-distance-fees-postflight.sql') -Destination (Join-Path $destination 'sql')
$manifest=[ordered]@{
    source_commit=$baseManifest.source_commit;source_branch=(git -C $repo branch --show-current)
    built_at_utc=[DateTime]::UtcNow.ToString('o');database_update_required=$false
    prerequisite_migration='044_onsite_distance_fees.sql';hosted_acceptance='pending'
    deployment_targets=[ordered]@{'wellness-api-practitioner-travel-patch.zip'='/wellness-api';'wellness-common-portal.zip'='/public_html/wellness-common-portal'}
    files=@()
}
foreach($file in Get-ChildItem -LiteralPath $destination -File -Recurse){
    $manifest.files += [ordered]@{file=$file.FullName.Substring($destination.Length+1).Replace('\','/');bytes=$file.Length;sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $destination 'manifest.json') -Encoding utf8
Write-Output "Practitioner travel deployment: $destination"
