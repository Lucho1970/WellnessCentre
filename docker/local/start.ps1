param(
    [Parameter(Mandatory = $true)][string]$BackupPath,
    [switch]$ConfirmSynthetic
)

$ErrorActionPreference = 'Stop'
if (-not $ConfirmSynthetic) { throw 'Confirm that every row in the backup is synthetic with -ConfirmSynthetic.' }
$repo = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$backup = (Resolve-Path -LiteralPath $BackupPath -ErrorAction Stop).Path
$settingsDir = Join-Path $repo '.tmp/docker-test'
$settingsFile = Join-Path $settingsDir 'compose.env'
New-Item -ItemType Directory -Path $settingsDir -Force | Out-Null

if (-not (Test-Path -LiteralPath $settingsFile -PathType Leaf)) {
    $randomHex = { [BitConverter]::ToString([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).Replace('-', '') }
    [System.IO.File]::WriteAllLines($settingsFile, @(
        'TEST_DB_ROOT_PASSWORD=' + (& $randomHex),
        'TEST_DB_PASSWORD=' + (& $randomHex),
        'TEST_APP_KEY=' + (& $randomHex)
    ))
}

$browserSettings = @{
    VITE_PUBLIC_URL = 'http://localhost:18083/'
    VITE_PORTAL_URL = 'http://localhost:18080/'
    VITE_API_BASE_URL = '/api/v1'
    VITE_ENTRA_REDIRECT_URI = ''
    VITE_RELEASE_NAME = 'local-docker-test'
    VITE_ENTRA_TENANT_ID = ''
    VITE_ENTRA_SPA_CLIENT_ID = ''
    VITE_ENTRA_API_CLIENT_ID = ''
    VITE_CUSTOMER_ENTRA_TENANT_ID = ''
    VITE_CUSTOMER_ENTRA_SUBDOMAIN = ''
    VITE_CUSTOMER_ENTRA_SPA_CLIENT_ID = ''
    VITE_CUSTOMER_ENTRA_API_CLIENT_ID = ''
    VITE_STAFF_EXTERNAL_TENANT_ID = ''
    VITE_STAFF_EXTERNAL_SUBDOMAIN = ''
    VITE_STAFF_EXTERNAL_SPA_CLIENT_ID = ''
    VITE_STAFF_EXTERNAL_API_CLIENT_ID = ''
    VITE_GOOGLE_MAPS_BROWSER_API_KEY = ''
    VITE_SERVER_SESSIONS_ENABLED = 'false'
    VITE_STAFF_INVITATIONS_ENABLED = 'false'
    VITE_PRACTITIONER_APPLICATIONS_ENABLED = 'false'
    VITE_STAFF_EXTERNAL_AUTH_ENABLED = 'false'
    VITE_PRACTITIONER_SOCIAL_SIGN_IN_ENABLED = 'false'
}
$previous = @{}
foreach ($name in $browserSettings.Keys) {
    $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    [Environment]::SetEnvironmentVariable($name, $browserSettings[$name], 'Process')
}

try {
    Push-Location (Join-Path $repo 'Frontend')
    try {
        if (-not (Test-Path 'node_modules' -PathType Container)) { & npm ci; if ($LASTEXITCODE -ne 0) { throw 'Frontend npm ci failed.' } }
        & npm run build
        if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }
    } finally { Pop-Location }

    Push-Location (Join-Path $repo 'Wellnexa')
    try {
        if (-not (Test-Path 'node_modules' -PathType Container)) { & npm ci; if ($LASTEXITCODE -ne 0) { throw 'Wellnexa npm ci failed.' } }
        & npm run lint
        if ($LASTEXITCODE -ne 0) { throw 'Wellnexa lint failed.' }
        & npm run build
        if ($LASTEXITCODE -ne 0) { throw 'Wellnexa build failed.' }
    } finally { Pop-Location }

    Push-Location (Join-Path $repo 'api')
    try {
        if (-not (Test-Path 'vendor/autoload.php' -PathType Leaf)) { & composer install --no-interaction; if ($LASTEXITCODE -ne 0) { throw 'Composer install failed.' } }
    } finally { Pop-Location }
} finally {
    foreach ($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
}

Push-Location $repo
try {
    $compose = Join-Path $PSScriptRoot 'compose.yaml'
    & docker compose --env-file $settingsFile -f $compose config --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Docker Compose configuration failed.' }
    & docker compose --env-file $settingsFile -f $compose build --quiet api
    if ($LASTEXITCODE -ne 0) { throw 'PHP image build failed.' }
    & docker compose --env-file $settingsFile -f $compose up -d
    if ($LASTEXITCODE -ne 0) { throw 'Docker Compose startup failed.' }
    & python (Join-Path $PSScriptRoot 'import-synthetic.py') --backup $backup --confirm-synthetic
    if ($LASTEXITCODE -ne 0) { throw 'Synthetic database import or migration failed.' }
} finally { Pop-Location }
