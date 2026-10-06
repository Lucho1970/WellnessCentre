param(
    [Parameter(Mandatory = $true)][string]$MariaDbDirectory,
    [string]$PhpExecutable = 'php',
    [ValidateRange(1024, 65535)][int]$Port = 13317
)
$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$runtime = (Resolve-Path -LiteralPath $MariaDbDirectory).Path
$installer = Join-Path $runtime 'bin/mariadb-install-db.exe'
$server = Join-Path $runtime 'bin/mariadbd.exe'
foreach ($binary in @($installer, $server)) {
    if (-not (Test-Path -LiteralPath $binary -PathType Leaf)) { throw "Missing portable MariaDB executable: $binary" }
}
$php = (Get-Command $PhpExecutable -ErrorAction Stop).Source
# Refuse an occupied port; never attach the test to an existing database server.
$probe = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
try { $probe.Start() } finally { $probe.Stop() }
$run = Join-Path $repo ('.tmp/recurring-sql-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $run | Out-Null
$data = Join-Path $run 'data'
$names = @('RECURRING_TEST_ALLOW_CREATE', 'RECURRING_TEST_PORT', 'RECURRING_TEST_USER', 'RECURRING_TEST_PASSWORD')
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
$process = $null
try {
    & $installer "--datadir=$data" "--port=$Port" --silent
    if ($LASTEXITCODE -ne 0) { throw 'Portable MariaDB initialization failed.' }
    # No Windows service, global configuration, remote root access or public listener.
    $args = @('--no-defaults', ('--basedir="' + $runtime + '"'), ('--datadir="' + $data + '"'), "--port=$Port", '--bind-address=127.0.0.1', '--console')
    $process = Start-Process -FilePath $server -ArgumentList $args -PassThru -WindowStyle Hidden -RedirectStandardOutput (Join-Path $run 'server.stdout.log') -RedirectStandardError (Join-Path $run 'server.stderr.log')
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        if ($process.HasExited) { throw "MariaDB exited before readiness. Inspect $run" }
        $socket = [System.Net.Sockets.TcpClient]::new()
        try { $socket.Connect('127.0.0.1', $Port); $ready = $true } catch { } finally { $socket.Dispose() }
        if ($ready) { break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw "MariaDB readiness timed out. Inspect $run" }
    [Environment]::SetEnvironmentVariable('RECURRING_TEST_ALLOW_CREATE', 'true', 'Process')
    [Environment]::SetEnvironmentVariable('RECURRING_TEST_PORT', [string]$Port, 'Process')
    [Environment]::SetEnvironmentVariable('RECURRING_TEST_USER', 'root', 'Process')
    [Environment]::SetEnvironmentVariable('RECURRING_TEST_PASSWORD', '', 'Process')
    & $php (Join-Path $repo 'api/tests/integration/recurring-bookings.php')
    if ($LASTEXITCODE -ne 0) { throw 'Real SQL recurrence acceptance failed.' }
} finally {
    if ($null -ne $process -and -not $process.HasExited) { Stop-Process -Id $process.Id; $process.WaitForExit(10000) | Out-Null }
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
    Write-Output "Server stopped; synthetic data and server logs retained at $run"
}
