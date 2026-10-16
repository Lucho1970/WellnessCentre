param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference='Stop'
if($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$'){throw 'Use letters, digits, underscores and hyphens for ReleaseName.'}
$repo=Split-Path $PSScriptRoot -Parent
if(@(git -C $repo status --porcelain --untracked-files=no).Count){throw 'Commit tracked source before packaging.'}
$destination=Join-Path $repo "deployments/$ReleaseName"
if(Test-Path -LiteralPath $destination){throw "Release already exists: $destination"}
$baseName="$ReleaseName-build"
& (Join-Path $PSScriptRoot 'build-deployment.ps1') -ReleaseName $baseName -DeploymentGuide FORM_LIBRARY_RELEASE.md
$base=Join-Path $repo "deployments/$baseName"
$baseManifest=Get-Content -LiteralPath (Join-Path $base 'manifest.json') -Raw | ConvertFrom-Json
New-Item -ItemType Directory -Path (Join-Path $destination 'sql') -Force | Out-Null
foreach($name in @('wellness-api-private.zip','wellness-common-portal.zip','copihue-portal-landing.zip')){Copy-Item -LiteralPath (Join-Path $base $name) -Destination $destination}
Copy-Item -LiteralPath (Join-Path $repo 'api/database/migrations/045_form_library.sql') -Destination (Join-Path $destination 'sql')
foreach($name in @('form-library-preflight.sql','form-library-postflight.sql')){Copy-Item -LiteralPath (Join-Path $repo "api/database/maintenance/$name") -Destination (Join-Path $destination 'sql')}
Copy-Item -LiteralPath (Join-Path $repo 'documentation/FORM_LIBRARY_RELEASE.md') -Destination (Join-Path $destination 'README.md')
Copy-Item -LiteralPath (Join-Path $repo 'documentation/DEPLOYMENT_LAYOUT.md') -Destination $destination
$manifest=[ordered]@{
    source_commit=$baseManifest.source_commit;source_branch=(git -C $repo branch --show-current)
    built_at_utc=[DateTime]::UtcNow.ToString('o');database_migration='045_form_library.sql'
    feature_flag='FORM_LIBRARY_ENABLED';hosted_acceptance='pending'
    deployment_targets=[ordered]@{'wellness-api-private.zip'='/wellness-api';'wellness-common-portal.zip'='/public_html/wellness-common-portal';'copihue-portal-landing.zip'='/public_html/wellness-portal'}
    files=@()
}
foreach($file in Get-ChildItem -LiteralPath $destination -File -Recurse){
    $manifest.files += [ordered]@{file=$file.FullName.Substring($destination.Length+1).Replace('\','/');bytes=$file.Length;sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $destination 'manifest.json') -Encoding utf8
Write-Output "Form library deployment: $destination"
