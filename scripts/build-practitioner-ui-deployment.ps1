param(
    [Parameter(Mandatory=$true)][string]$ReleaseName,
    [Parameter(Mandatory=$true)][string]$BaselineManifest
)
$ErrorActionPreference='Stop'
if($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$'){throw 'Use letters, digits, underscores and hyphens for ReleaseName.'}
$repo=Split-Path $PSScriptRoot -Parent
if(@(git -C $repo status --porcelain --untracked-files=no).Count){throw 'Commit tracked source before packaging.'}
$destination=Join-Path $repo "deployments/$ReleaseName"
if(Test-Path -LiteralPath $destination){throw 'Release destination already exists.'}
$source=(git -C $repo rev-parse HEAD).Trim()
$baselinePath=(Resolve-Path -LiteralPath $BaselineManifest).Path
$baselineManifestData=Get-Content -LiteralPath $baselinePath -Raw|ConvertFrom-Json
$rollout=$baselineManifestData.practitioner_rollout
if(!$rollout){throw 'A reviewed previous practitioner build manifest is required.'}
foreach($name in @('tenant_id','staff_spa','staff_api')){
    $parsedGuid=[guid]::Empty
    if(![guid]::TryParse([string]$rollout.$name,[ref]$parsedGuid) -or $parsedGuid -eq [guid]::Empty){throw "Invalid baseline public identifier: $name"}
}
if($rollout.subdomain -notmatch '^[a-z0-9][a-z0-9-]{0,62}$'){throw 'Invalid baseline identity hostname.'}
$baselineArchive=Join-Path (Split-Path $baselinePath -Parent) 'wellness-common-portal.zip'
$expectedArchive=@($baselineManifestData.archives|Where-Object {$_.file -eq 'wellness-common-portal.zip'})
if($expectedArchive.Count -ne 1 -or (Get-FileHash -LiteralPath $baselineArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedArchive[0].sha256){throw 'Previous portal archive integrity did not match the baseline manifest.'}
$previous=@{}
$overrides=@{
    VITE_RELEASE_NAME=$ReleaseName;VITE_PUBLIC_URL='https://livinlively.ca/';VITE_PORTAL_URL='https://livinlively.copihue.ca/'
    VITE_STAFF_EXTERNAL_TENANT_ID=[string]$rollout.tenant_id;VITE_STAFF_EXTERNAL_SUBDOMAIN=[string]$rollout.subdomain
    VITE_STAFF_EXTERNAL_SPA_CLIENT_ID=[string]$rollout.staff_spa;VITE_STAFF_EXTERNAL_API_CLIENT_ID=[string]$rollout.staff_api
}
$flagMap=@{VITE_STAFF_EXTERNAL_AUTH_ENABLED='external_auth_enabled_in_build';VITE_PRACTITIONER_APPLICATIONS_ENABLED='applications_enabled_in_build';VITE_PRACTITIONER_SOCIAL_SIGN_IN_ENABLED='social_choices_enabled_in_build';VITE_STAFF_INVITATIONS_ENABLED='invitations_enabled_in_build'}
foreach($name in $flagMap.Keys){
    $value=$rollout.($flagMap[$name]);if($value -isnot [bool]){throw "Missing baseline build flag: $name"}
    $overrides[$name]=$value.ToString().ToLowerInvariant()
}
foreach($name in $overrides.Keys){$previous[$name]=[Environment]::GetEnvironmentVariable($name,'Process');[Environment]::SetEnvironmentVariable($name,$overrides[$name],'Process')}
Push-Location (Join-Path $repo 'Frontend')
try {npm run build;if($LASTEXITCODE -ne 0){throw 'Frontend quality/build gate failed.'}}
finally {Pop-Location;foreach($name in $previous.Keys){[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}}
if((git -C $repo rev-parse HEAD).Trim() -ne $source -or @(git -C $repo status --porcelain --untracked-files=no).Count){throw 'Source changed during build.'}
$portalScripts=(Get-ChildItem -LiteralPath (Join-Path $repo 'Frontend/dist/portal/assets') -File -Filter '*.js'|ForEach-Object{[IO.File]::ReadAllText($_.FullName)}) -join "`n"
foreach($name in @('VITE_STAFF_EXTERNAL_TENANT_ID','VITE_STAFF_EXTERNAL_SUBDOMAIN','VITE_STAFF_EXTERNAL_SPA_CLIENT_ID','VITE_STAFF_EXTERNAL_API_CLIENT_ID')){
    if(!$portalScripts.Contains($overrides[$name])){throw "Built portal is missing the reviewed identity setting: $name"}
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$baselineZip=[IO.Compression.ZipFile]::OpenRead($baselineArchive)
try {
    $baselineEntry=$baselineZip.GetEntry('index.html');if(!$baselineEntry){throw 'Previous archive has no portal entry.'}
    $baselineStream=$baselineEntry.Open()
    try {$baselinePortalHash=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($baselineStream)).ToLowerInvariant()}
    finally {$baselineStream.Dispose()}
} finally {$baselineZip.Dispose()}
New-Item -ItemType Directory -Path $destination | Out-Null
$surfaces=@(
    @{source='Frontend/dist/portal';target='wellness-common-portal';entry='index.html';url='https://wellnexa.copihue.ca/'},
    @{source='Frontend/dist/application-admin';target='wellness-portal/admin';entry='central.html';url='https://portal.copihue.ca/admin/'}
)
$assetZip=[IO.Compression.ZipFile]::Open((Join-Path $destination '01-assets.zip'),[IO.Compression.ZipArchiveMode]::Create)
$entryZip=[IO.Compression.ZipFile]::Open((Join-Path $destination '02-entry-pages.zip'),[IO.Compression.ZipArchiveMode]::Create)
$rollbackZip=[IO.Compression.ZipFile]::Open((Join-Path $destination 'ROLLBACK-entry-pages.zip'),[IO.Compression.ZipArchiveMode]::Create)
$members=[Collections.Generic.List[object]]::new()
$baselines=[Collections.Generic.List[object]]::new()
$stage=Join-Path $repo ('.tmp/ui-entries-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $stage | Out-Null
try {
    foreach($surface in $surfaces){
        $root=(Resolve-Path -LiteralPath (Join-Path $repo $surface.source)).Path
        foreach($file in Get-ChildItem -LiteralPath $root -File -Recurse -Force){
            $relative=$file.FullName.Substring($root.Length+1).Replace('\','/')
            # Only built JS/CSS and HTML are in this incremental frontend release.
            if($relative -eq $surface.entry){$archive=$entryZip;$archiveName='02-entry-pages.zip';$relative='index.html'}
            elseif($relative -match '^assets/[a-zA-Z0-9_.-]+\.(js|css)$'){$archive=$assetZip;$archiveName='01-assets.zip'}
            elseif($relative -match '^assets/'){throw "Unexpected build asset type: $relative"}
            else {continue}
            $body=[IO.File]::ReadAllText($file.FullName)
            if($body -match '(?i)(https?://(localhost|127\.0\.0\.1)|sourceMappingURL|-----BEGIN [A-Z ]*PRIVATE KEY-----|gh[pousr]_[a-zA-Z0-9]{20,}|eyJ[a-zA-Z0-9_-]{10,}\.eyJ[a-zA-Z0-9_-]{10,}\.[a-zA-Z0-9_-]{10,})'){throw "Artifact requires endpoint/credential review: $relative"}
            $member=$surface.target+'/'+$relative
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive,$file.FullName,$member,[IO.Compression.CompressionLevel]::Optimal)|Out-Null
            $members.Add(@{archive=$archiveName;path=$member;bytes=$file.Length;sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()})
        }
        # Public anonymous HTML only. Never retrieve private configuration or database data.
        $response=Invoke-WebRequest -Uri $surface.url -TimeoutSec 25
        if($response.StatusCode -ne 200 -or $response.Content -notmatch '(?i)<!doctype html' -or $response.Content -notmatch '<script'){throw 'Current hosted entry is not an accepted HTML baseline.'}
        $baseline=Join-Path $stage ([guid]::NewGuid().ToString('N')+'.html')
        [IO.File]::WriteAllBytes($baseline,$response.RawContentStream.ToArray())
        if($surface.target -eq 'wellness-common-portal' -and (Get-FileHash -LiteralPath $baseline -Algorithm SHA256).Hash.ToLowerInvariant() -ne $baselinePortalHash){throw 'Current hosted portal entry differs from the reviewed configuration baseline; review before packaging.'}
        $member=$surface.target+'/index.html'
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($rollbackZip,$baseline,$member,[IO.Compression.CompressionLevel]::Optimal)|Out-Null
        $baselines.Add(@{url=$surface.url;path=$member;sha256=(Get-FileHash -LiteralPath $baseline -Algorithm SHA256).Hash.ToLowerInvariant()})
    }
} finally {$assetZip.Dispose();$entryZip.Dispose();$rollbackZip.Dispose()}
$archives=@(Get-ChildItem -LiteralPath $destination -Filter '*.zip' -File | ForEach-Object {
    $zip=[IO.Compression.ZipFile]::OpenRead($_.FullName)
    try {foreach($entry in $zip.Entries){$stream=$entry.Open();try{$stream.CopyTo([IO.Stream]::Null)}finally{$stream.Dispose()}}}finally{$zip.Dispose()}
    @{name=$_.Name;bytes=$_.Length;sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()}
})
$lockfiles=@('Frontend/package-lock.json','Wellnexa/package-lock.json','api/composer.lock')|ForEach-Object{@{path=$_;sha256=(Get-FileHash -LiteralPath (Join-Path $repo $_) -Algorithm SHA256).Hash.ToLowerInvariant()}}
@{source_commit=$source;source_branch=(git -C $repo branch --show-current).Trim();built_at_utc=[DateTime]::UtcNow.ToString('o');release=$ReleaseName;
    node=(& node --version);npm=(& npm --version);lockfiles=@($lockfiles);archives=$archives;members=@($members.ToArray());previous_entries=@($baselines.ToArray());
    baseline_manifest_sha256=(Get-FileHash -LiteralPath $baselinePath -Algorithm SHA256).Hash.ToLowerInvariant();public_build_settings=$overrides;
    target='/public_html';private_api_change=$false;sql_change=$false;configuration_change=$false;hosted_acceptance='pending';
    transfer='Merge 01-assets first, verify bytes, then replace only 02-entry-pages. Retain all old assets. Keep rollback and manifest local.'
}|ConvertTo-Json -Depth 7|Set-Content -LiteralPath (Join-Path $destination 'manifest.json') -Encoding utf8
Copy-Item -LiteralPath (Join-Path $repo 'documentation/PRACTITIONER_UI_RELIABILITY_2026-11-02.md') -Destination (Join-Path $destination 'README.md')
Write-Output "Frontend-only review package: $destination"
