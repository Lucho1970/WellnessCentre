param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference='Stop'
if($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$'){throw 'Use letters, digits, underscores, and hyphens for ReleaseName.'}
$repo=Split-Path $PSScriptRoot -Parent
if(@(git -C $repo status --porcelain --untracked-files=no).Count){throw 'Commit tracked source changes before packaging so the manifest identifies the exact source.'}
$destination=Join-Path $repo "deployments/$ReleaseName"
if(Test-Path -LiteralPath $destination){throw "Release already exists: $destination"}
$baseName="$ReleaseName-build"
& (Join-Path $PSScriptRoot 'build-deployment.ps1') -ReleaseName $baseName -DeploymentGuide SESSION_RECEIPTS_RELEASE.md
$base=Join-Path $repo "deployments/$baseName"
$baseManifest=Get-Content -LiteralPath (Join-Path $base 'manifest.json') -Raw | ConvertFrom-Json
New-Item -ItemType Directory -Path (Join-Path $destination 'sql') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $base 'wellness-api-private.zip') -Destination $destination
Copy-Item -LiteralPath (Join-Path $base 'wellness-common-portal.zip') -Destination $destination
Copy-Item -LiteralPath (Join-Path $repo 'api/database/migrations/043_session_receipts.sql') -Destination (Join-Path $destination 'sql')
foreach($name in @('session-receipts-preflight.sql','session-receipts-postflight.sql')){
    Copy-Item -LiteralPath (Join-Path $repo "api/database/maintenance/$name") -Destination (Join-Path $destination 'sql')
}
Copy-Item -LiteralPath (Join-Path $repo 'documentation/SESSION_RECEIPTS_RELEASE.md') -Destination (Join-Path $destination 'README.md')
Copy-Item -LiteralPath (Join-Path $repo 'documentation/SESSION_RECEIPTS.md') -Destination $destination
Add-Type -AssemblyName System.IO.Compression.FileSystem
foreach($name in @('wellness-api-private.zip','wellness-common-portal.zip')){
    $archive=[System.IO.Compression.ZipFile]::OpenRead((Join-Path $destination $name))
    try{
        $entries=@($archive.Entries | ForEach-Object {$_.FullName})
        if(@($entries | Where-Object {$_ -match '(^|/)(\.env[^/]*|\.git|node_modules)(/|$)'}).Count){throw "Unexpected private/development file in $name"}
        $required=if($name -eq 'wellness-api-private.zip'){@('src/Service/SessionReceiptService.php','src/Service/ReceiptPdf.php','vendor/autoload.php','vendor/dompdf/dompdf/src/Dompdf.php','vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf')}else{@('index.html','.htaccess','api/index.php','api/.htaccess')}
        foreach($entry in $required){if($entries -notcontains $entry){throw "Missing $entry in $name"}}
    }finally{$archive.Dispose()}
}
$manifest=[ordered]@{
    release_name=$ReleaseName;source_commit=$baseManifest.source_commit;source_branch=(git -C $repo branch --show-current)
    built_at_utc=[DateTime]::UtcNow.ToString('o');frontend_release_name=$baseName
    api_update_required=$true;database_migration='043_session_receipts.sql';feature_flag='SESSION_RECEIPTS_ENABLED'
    deployment_targets=[ordered]@{'wellness-api-private.zip'='/wellness-api';'wellness-common-portal.zip'='/public_html/wellness-common-portal'};wellnexa_theme_included=$true;hosted_acceptance='pending';files=@()
}
foreach($file in Get-ChildItem -LiteralPath $destination -File -Recurse){
    $manifest.files += [ordered]@{file=$file.FullName.Substring($destination.Length+1).Replace('\','/');bytes=$file.Length;sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $destination 'manifest.json') -Encoding utf8
$bundle=Join-Path $repo ('.tmp/receipt-package-'+[guid]::NewGuid().ToString('N')+'.zip')
[System.IO.Compression.ZipFile]::CreateFromDirectory($destination,$bundle,[System.IO.Compression.CompressionLevel]::Optimal,$false)
Move-Item -LiteralPath $bundle -Destination (Join-Path $destination 'wellnexa-receipts-package.zip')
Get-FileHash -LiteralPath (Join-Path $destination 'wellnexa-receipts-package.zip') -Algorithm SHA256 | ForEach-Object {"$($_.Hash.ToLowerInvariant())  wellnexa-receipts-package.zip"} | Set-Content -LiteralPath (Join-Path $destination 'SHA256SUMS.txt') -Encoding ascii
Write-Output "Receipt deployment package: $destination"
