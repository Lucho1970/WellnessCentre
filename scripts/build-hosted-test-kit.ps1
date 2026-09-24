param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference = 'Stop'
if ($ReleaseName -notmatch '^[a-zA-Z0-9_-]+$') { throw 'Use letters, digits, underscores, and hyphens for ReleaseName.' }
$repo = Split-Path $PSScriptRoot -Parent
$destination = Join-Path $repo "deployments/$ReleaseName"
if (Test-Path -LiteralPath $destination) { throw "Release already exists: $destination" }
$stage = Join-Path $repo ('.tmp/hosted-tests-' + [guid]::NewGuid().ToString('N'))
$private = Join-Path $stage 'wellness-api'
$public = Join-Path $stage 'public_html/wellness/api'
New-Item -ItemType Directory -Path (Join-Path $private 'tests/hosted'),(Join-Path $private 'tests/integration'),$public -Force | Out-Null
try {
    Get-ChildItem -LiteralPath (Join-Path $repo 'api/tests') -Filter '*.php' -File | Copy-Item -Destination (Join-Path $private 'tests') -ErrorAction Stop
    Copy-Item -LiteralPath (Join-Path $repo 'api/tests/hosted/Suite.php'),(Join-Path $repo 'api/tests/hosted/database-read.php'),(Join-Path $repo 'api/tests/hosted/HttpBookingWorker.php') -Destination (Join-Path $private 'tests/hosted')
    foreach ($name in @('booking-race.php','booking-race-worker.php')) {
        Copy-Item -LiteralPath (Join-Path $repo "api/tests/integration/$name") -Destination (Join-Path $private 'tests/integration')
    }
    Copy-Item -LiteralPath (Join-Path $repo 'api/tests/hosted/test-suite-web.php') -Destination (Join-Path $public 'test-suite.php')
    $supports = @(
        @('api/deploy/netfirms/public/cron/send-notifications.php','deploy/netfirms/public/cron/send-notifications.php'),
        @('hosting/netfirms/main-domain/api/wellness-notification-trigger.php','hosting/netfirms/main-domain/api/wellness-notification-trigger.php')
    )
    foreach ($pair in $supports) {
        $target = Join-Path $private $pair[1]
        New-Item -ItemType Directory -Path (Split-Path $target -Parent) -Force | Out-Null
        Copy-Item -LiteralPath (Join-Path $repo $pair[0]) -Destination $target
    }
    New-Item -ItemType Directory -Path $destination | Out-Null
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = Join-Path $destination 'hosted-test-kit.zip'
    $zipWriter = [System.IO.Compression.ZipFile]::Open($zip,[System.IO.Compression.ZipArchiveMode]::Create)
    try {
        $stageRoot = (Resolve-Path -LiteralPath $stage).Path
        foreach ($file in Get-ChildItem -LiteralPath $stage -Recurse -File) {
            $entry = $file.FullName.Substring($stageRoot.Length + 1).Replace('\','/')
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipWriter,$file.FullName,$entry,[System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally { $zipWriter.Dispose() }
    $archive = [System.IO.Compression.ZipFile]::OpenRead($zip)
    try {
        $entries = @($archive.Entries | ForEach-Object FullName)
        if (@($entries | Where-Object { $_ -match '(^|/)(\.env[^/]*|vendor|src)(/|$)' }).Count -ne 0) { throw 'Test archive includes application secrets or code.' }
        if ($entries -notcontains 'public_html/wellness/api/test-suite.php') { throw 'Public test endpoint is missing.' }
        if ($entries -notcontains 'wellness-api/tests/hosted/Suite.php') { throw 'Private runner is missing.' }
        $manifest = [ordered]@{ built_at_utc=[DateTime]::UtcNow.ToString('o'); archive='hosted-test-kit.zip'; sha256=(Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant(); entries=$entries.Count; public_entry='public_html/wellness/api/test-suite.php'; private_root='wellness-api/tests'; deployment_guide='documentation/HOSTED_TEST_SUITE.md' }
        $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $destination 'manifest.json') -Encoding utf8
    } finally { $archive.Dispose() }
    Write-Output "Hosted test kit: $zip"
} finally {
    if (Test-Path -LiteralPath $stage) {
        $resolvedStage = (Resolve-Path -LiteralPath $stage).Path
        $resolvedRoot = (Resolve-Path -LiteralPath (Join-Path $repo '.tmp')).Path
        if (-not $resolvedStage.StartsWith($resolvedRoot + [IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)) { throw 'Refusing to remove stage outside workspace .tmp.' }
        Remove-Item -LiteralPath $resolvedStage -Recurse -Force
    }
}
