param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference='Stop'
if($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$'){throw 'Use letters, digits, underscores and hyphens for ReleaseName.'}
$repo=Split-Path $PSScriptRoot -Parent
if(@(git -C $repo status --porcelain --untracked-files=no).Count){throw 'Commit tracked source before packaging.'}
$destination=Join-Path $repo "deployments/$ReleaseName"
if(Test-Path -LiteralPath $destination){throw "Release already exists: $destination"}
$settings=@{}
foreach($file in @('Frontend/.env.production','Frontend/.env.production.local')){
    $path=Join-Path $repo $file
    if(Test-Path -LiteralPath $path){foreach($line in Get-Content -LiteralPath $path){
        if($line -match '^\s*([^#][^=]*)=(.*)$'){$settings[$matches[1].Trim()]=$matches[2].Trim()}
    }}
}
$required=@('VITE_ENTRA_TENANT_ID','VITE_ENTRA_API_CLIENT_ID','VITE_ENTRA_SPA_CLIENT_ID','VITE_CUSTOMER_ENTRA_TENANT_ID','VITE_CUSTOMER_ENTRA_SUBDOMAIN','VITE_CUSTOMER_ENTRA_API_CLIENT_ID','VITE_CUSTOMER_ENTRA_SPA_CLIENT_ID')
foreach($name in $required){
    $value=[Environment]::GetEnvironmentVariable($name,'Process')
    if([string]::IsNullOrWhiteSpace($value)){$value=$settings[$name]}
    if([string]::IsNullOrWhiteSpace($value)){throw "Missing production frontend setting: $name"}
    $settings[$name]=$value
}
Push-Location (Join-Path $repo 'Frontend')
try { npm run build:portal; if($LASTEXITCODE -ne 0){throw 'Portal build failed.'} } finally { Pop-Location }
$scripts=Get-ChildItem -LiteralPath (Join-Path $repo 'Frontend/dist/portal/assets') -Filter '*.js' -File
foreach($name in $required){
    if(-not @($scripts | Where-Object {[IO.File]::ReadAllText($_.FullName).Contains($settings[$name])}).Count){throw "Portal bundle is missing production setting: $name"}
}
$stage=Join-Path $repo ('.tmp/portal-only-'+[guid]::NewGuid().ToString('N'))
Copy-Item -LiteralPath (Join-Path $repo 'Frontend/dist/portal') -Destination $stage -Recurse
foreach($name in @('.htaccess','share-preview.php','share-preview-lib.php')){
    Copy-Item -LiteralPath (Join-Path $repo "hosting/netfirms/portal/$name") -Destination $stage
}
Copy-Item -LiteralPath (Join-Path $repo 'api/deploy/netfirms/public') -Destination (Join-Path $stage 'api') -Recurse
foreach($name in @('index.html','.htaccess','api/index.php','api/.htaccess','api/runtime-config.php')){
    if(-not(Test-Path -LiteralPath (Join-Path $stage $name))){throw "Missing portal file: $name"}
}
if(@(Get-ChildItem -LiteralPath $stage -Recurse -Force -File | Where-Object {$_.Name -like '.env*'}).Count){throw 'Unexpected environment file in portal staging.'}
New-Item -ItemType Directory -Path $destination | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive=Join-Path $destination 'wellnexa-portal.zip'
[System.IO.Compression.ZipFile]::CreateFromDirectory($stage,$archive,[System.IO.Compression.CompressionLevel]::Optimal,$false)
Copy-Item -LiteralPath (Join-Path $repo 'documentation/CLINIC_BOOKING_HANDOFF_RELEASE.md') -Destination (Join-Path $destination 'README.md')
$manifest=[ordered]@{
    source_commit=(git -C $repo rev-parse HEAD);source_branch=(git -C $repo branch --show-current)
    built_at_utc=[DateTime]::UtcNow.ToString('o');archive='wellnexa-portal.zip'
    sha256=(Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
    private_api_update_required=$false;database_update_required=$false;hosted_acceptance='pending'
}
$manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $destination 'manifest.json') -Encoding utf8
Write-Output "Portal-only package: $destination"
