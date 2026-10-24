param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference='Stop'
if($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$'){throw 'Use a simple release name.'}
$repo=Split-Path $PSScriptRoot -Parent
if(@(git -C $repo status --porcelain --untracked-files=no).Count){throw 'Commit tracked source before packaging.'}
$destination=Join-Path $repo "deployments/$ReleaseName"
if(Test-Path -LiteralPath $destination){throw "Release already exists: $destination"}
New-Item -ItemType Directory -Path $destination | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem
$relative='src/Service/TravelEnquiryService.php'
$source=Join-Path $repo "api/$relative"
$archivePath=Join-Path $destination 'wellness-api-travel-inbox-patch.zip'
$archive=[IO.Compression.ZipFile]::Open($archivePath,[IO.Compression.ZipArchiveMode]::Create)
try{[IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive,$source,$relative,[IO.Compression.CompressionLevel]::Optimal) | Out-Null}finally{$archive.Dispose()}
Copy-Item -LiteralPath (Join-Path $repo 'documentation/TRAVEL_ENQUIRY_MYSQL57_FIX.md') -Destination (Join-Path $destination 'README.md')
$manifest=[ordered]@{
    release=$ReleaseName;source_revision=(git -C $repo rev-parse HEAD);source_branch=(git -C $repo branch --show-current)
    built_at_utc=[DateTime]::UtcNow.ToString('o');destination='/wellness-api'
    files=@([ordered]@{path=$relative;sha256=(Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant()})
    archive='wellness-api-travel-inbox-patch.zip';archive_sha256=(Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    database_migrations_required=$false;frontend_update_required=$false;hosted_acceptance='pending'
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $destination 'release-manifest.json') -Encoding utf8
Write-Output "Travel inbox patch: $destination"
