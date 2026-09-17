param([Parameter(Mandatory=$true)][string]$ReleaseName)
$ErrorActionPreference = 'Stop'
# Public development identifiers only. Federation secrets never enter a frontend build.
$settings = @{
    VITE_PUBLIC_URL = 'https://wellness.copihue.ca/'
    VITE_PORTAL_URL = 'https://portal.copihue.ca/'
    VITE_API_BASE_URL = 'https://wellness.copihue.ca/api/v1'
    VITE_ENTRA_TENANT_ID = 'dcbc99f5-04a8-40d6-8a2e-69633e3673ff'
    VITE_ENTRA_SPA_CLIENT_ID = 'd5c68a6f-cde5-40f5-843f-eac5a3160065'
    VITE_ENTRA_API_CLIENT_ID = '923bdf31-87c8-44c7-9396-3d3860b7e678'
    VITE_ENTRA_REDIRECT_URI = 'https://portal.copihue.ca/'
    VITE_CUSTOMER_ENTRA_TENANT_ID = '0a3841c6-b244-410d-821f-bbd9ccd1b5e2'
    VITE_CUSTOMER_ENTRA_SUBDOMAIN = 'copihuewellnessclientsdev'
    VITE_CUSTOMER_ENTRA_API_CLIENT_ID = '08542bbb-09cc-4737-979b-ca61c7eec70d'
    VITE_CUSTOMER_ENTRA_SPA_CLIENT_ID = '7a522317-d74f-4ccb-9805-8e4b912c02ab'
}
$previous = @{}
try {
    foreach ($name in $settings.Keys) {
        $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $settings[$name], 'Process')
    }
    & "$PSScriptRoot/build-deployment.ps1" -ReleaseName $ReleaseName
} finally {
    foreach ($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
}
