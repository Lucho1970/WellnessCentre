param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference='Stop'
$repo=Split-Path $PSScriptRoot -Parent
if($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$'){throw 'Use a simple unique release name.'}
if(@(git -C $repo status --porcelain --untracked-files=no).Count){throw 'Commit tracked source before packaging.'}
$destination=Join-Path $repo "deployments/$ReleaseName"
if(Test-Path -LiteralPath $destination){throw 'Release directory already exists.'}
New-Item -ItemType Directory -Path $destination|Out-Null
$files=@('src/Api.php','src/Service/LocationDiscoveryService.php','src/Service/DiscoveryMapsClient.php','bin/discovery-preflight.php')
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zipPath=Join-Path $destination 'wellness-api-maps-diagnostics.zip'
$zip=[IO.Compression.ZipFile]::Open($zipPath,[IO.Compression.ZipArchiveMode]::Create)
try{foreach($file in $files){[IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip,(Join-Path $repo "api/$file"),$file,[IO.Compression.CompressionLevel]::Optimal)|Out-Null}}finally{$zip.Dispose()}
Copy-Item -LiteralPath (Join-Path $repo 'documentation/WELLNEXA_MAPS_DIAGNOSTICS.md') -Destination (Join-Path $destination 'README.md')
$manifest=[ordered]@{release_name=$ReleaseName;source_commit=(git -C $repo rev-parse HEAD);source_branch=(git -C $repo branch --show-current);built_at_utc=[DateTime]::UtcNow.ToString('o');base_application_source='f738e22b1320aab5fd2c1647bbe7a719fcf7d116';target='/wellness-api';archive='wellness-api-maps-diagnostics.zip';sha256=(Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant();files=@();database_change=$false;configuration_change=$false;hosted_provider_cause='Unverified; use redacted logs or explicit operator preflight.';release_status='NOT READY FOR RELEASE'}
$readZip=[IO.Compression.ZipFile]::OpenRead($zipPath)
try{
    if($readZip.Entries.Count -ne $files.Count){throw 'Unexpected patch contents.'}
    foreach($file in $files){
        $entry=$readZip.GetEntry($file);if($null -eq $entry){throw 'Missing patch file.'}
        $stream=$entry.Open();$sha=[Security.Cryptography.SHA256]::Create()
        try{$hash=[BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-','').ToLowerInvariant()}finally{$stream.Dispose();$sha.Dispose()}
        if($hash -ne (Get-FileHash -LiteralPath (Join-Path $repo "api/$file") -Algorithm SHA256).Hash.ToLowerInvariant()){throw 'Patch source mismatch.'}
        $manifest.files += [ordered]@{file=$file;sha256=$hash}
    }
}finally{$readZip.Dispose()}
$manifest|ConvertTo-Json -Depth 5|Set-Content -LiteralPath (Join-Path $destination 'manifest.json') -Encoding utf8
Write-Output "Verified four-file private API patch: $destination"
