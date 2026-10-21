param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference='Stop'
if($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$'){throw 'Use letters, digits, underscores and hyphens for ReleaseName.'}
$repo=Split-Path $PSScriptRoot -Parent
if(@(git -C $repo status --porcelain --untracked-files=no).Count){throw 'Commit tracked source before packaging.'}
$destination=Join-Path $repo "deployments/$ReleaseName"
if(Test-Path -LiteralPath $destination){throw "Release already exists: $destination"}
$stage=Join-Path $repo ('.tmp/time-off-patch-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path (Join-Path $stage 'src/Service') -Force | Out-Null
$source=Join-Path $repo 'api/src/Service/AvailabilityService.php'
Copy-Item -LiteralPath $source -Destination (Join-Path $stage 'src/Service')
New-Item -ItemType Directory -Path $destination | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive=Join-Path $destination 'wellness-api-time-off-patch.zip'
[System.IO.Compression.ZipFile]::CreateFromDirectory($stage,$archive,[System.IO.Compression.CompressionLevel]::Optimal,$false)
Copy-Item -LiteralPath (Join-Path $repo 'documentation/CLINIC_TIME_OFF_RELEASE.md') -Destination (Join-Path $destination 'README.md')
$manifest=[ordered]@{
    source_commit=(git -C $repo rev-parse HEAD);source_branch=(git -C $repo branch --show-current)
    built_at_utc=[DateTime]::UtcNow.ToString('o');deployment_type='private API single-file patch'
    archive='wellness-api-time-off-patch.zip';sha256=(Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
    file='src/Service/AvailabilityService.php';file_sha256=(Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant()
    database_update_required=$false;portal_update_required=$false;hosted_acceptance='pending'
}
$manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $destination 'manifest.json') -Encoding utf8
Write-Output "Clinic time-off patch: $destination"
