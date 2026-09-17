param(
    [string]$PublicUrl = 'https://wellness.copihue.ca/',
    [string]$PortalUrl = 'https://portal.copihue.ca/'
)
$ErrorActionPreference = 'Stop'
$failed = $false
$targets = @(
    $PublicUrl, ($PublicUrl.TrimEnd('/') + '/book'),
    $PortalUrl, ($PortalUrl.TrimEnd('/') + '/client'),
    ($PortalUrl.TrimEnd('/') + '/staff/login')
)
foreach ($url in $targets) {
    $response = Invoke-WebRequest -Uri $url -TimeoutSec 20
    $cache = $response.Headers['Cache-Control'] -join ','
    $type = $response.Headers['Content-Type'] -join ','
    $ok = $response.StatusCode -eq 200 -and $type -match 'text/html' -and
        $cache -match '(^|[,\s])no-store([,\s]|$)' -and
        $cache -match '(^|[,\s])no-cache([,\s]|$)' -and
        $cache -notmatch '(?:s-maxage|max-age)\s*=\s*[1-9]'
    [pscustomobject]@{ Url = $url; Passed = $ok; CacheControl = $cache }
    if (-not $ok) { $failed = $true }
}
if ($failed) { throw 'HTML cache policy failed. Check uploaded .htaccess and hosting/CDN overrides.' }
