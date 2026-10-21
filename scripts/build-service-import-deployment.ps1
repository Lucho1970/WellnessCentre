param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference='Stop'
if($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$'){throw 'Use letters, digits, underscores and hyphens for ReleaseName.'}
$repo=Split-Path $PSScriptRoot -Parent
if(@(git -C $repo status --porcelain --untracked-files=no).Count){throw 'Commit tracked source before packaging.'}
$destination=Join-Path $repo "deployments/$ReleaseName"
$download=Join-Path $repo "deployments/$ReleaseName-package.zip"
if((Test-Path -LiteralPath $destination) -or (Test-Path -LiteralPath $download)){throw "Release already exists: $ReleaseName"}
$baseName="$ReleaseName-build"
& (Join-Path $PSScriptRoot 'build-deployment.ps1') -ReleaseName $baseName -DeploymentGuide SERVICE_IMPORT_RELEASE.md
$base=Join-Path $repo "deployments/$baseName"
$baseManifest=Get-Content -LiteralPath (Join-Path $base 'manifest.json') -Raw | ConvertFrom-Json
if($baseManifest.source_commit -ne (git -C $repo rev-parse HEAD)){throw 'Source changed during packaging.'}
New-Item -ItemType Directory -Path (Join-Path $destination 'sql') -Force | Out-Null
foreach($file in @('wellness-api-private.zip','wellness-common-portal.zip')){
    Copy-Item -LiteralPath (Join-Path $base $file) -Destination $destination
}
Copy-Item -LiteralPath (Join-Path $repo 'api/database/migrations/052_service_imports.sql') -Destination (Join-Path $destination 'sql')
Copy-Item -LiteralPath (Join-Path $repo 'documentation/SERVICE_IMPORT_RELEASE.md') -Destination (Join-Path $destination 'README.md')
foreach($file in @('SERVICE_IMPORT.md','DEPLOYMENT_LAYOUT.md')){
    Copy-Item -LiteralPath (Join-Path $repo "documentation/$file") -Destination $destination
}
$manifest=[ordered]@{
    release_name=$ReleaseName;source_commit=$baseManifest.source_commit;source_branch=(git -C $repo branch --show-current)
    built_at_utc=[DateTime]::UtcNow.ToString('o');pull_requests=@(11,12)
    database_migration='sql/052_service_imports.sql';migration_before_code=$true
    hosted_acceptance='pending';live_import_performed=$false;catalogue_file_included=$false
    deployment_targets=[ordered]@{'wellness-api-private.zip'='/wellness-api';'wellness-common-portal.zip'='/public_html/wellness-common-portal'}
    files=@()
}
foreach($file in Get-ChildItem -LiteralPath $destination -Recurse -File){
    $manifest.files += [ordered]@{file=$file.FullName.Substring($destination.Length+1).Replace('\','/');bytes=$file.Length;sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$manifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $destination 'manifest.json') -Encoding utf8
[IO.Compression.ZipFile]::CreateFromDirectory($destination,$download,[IO.Compression.CompressionLevel]::Optimal,$false)
$checksum=(Get-FileHash -LiteralPath $download -Algorithm SHA256).Hash.ToLowerInvariant()
"$checksum  $([IO.Path]::GetFileName($download))" | Set-Content -LiteralPath "$download.sha256" -Encoding ascii
Write-Output "Deployment folder: $destination"
Write-Output "Download package: $download"
