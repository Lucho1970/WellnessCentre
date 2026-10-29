param(
    [Parameter(Mandatory = $true)][string]$MySqlDirectory,
    [string]$PhpExecutable = 'php',
    [ValidateRange(1024, 65535)][int]$Port = 13337,
    [ValidateSet('provider-evidence.php','automated-communications.php','practitioner-applications.php','billing-administration.php','subscription-foundation.php','travel-enquiry-inbox.php','booking-client-access.php')][string]$IntegrationTest = 'travel-enquiry-inbox.php'
)
$ErrorActionPreference = 'Stop'
$repo = Split-Path $PSScriptRoot -Parent
$runtime = (Resolve-Path -LiteralPath $MySqlDirectory).Path
$server = Join-Path $runtime 'bin/mysqld.exe'
if (-not (Test-Path -LiteralPath $server -PathType Leaf)) { throw "Missing portable MySQL server: $server" }
$version = & $server --no-defaults --version
if ($LASTEXITCODE -ne 0 -or $version -notmatch '\bVer 5\.7\.') { throw 'This compatibility runner requires a MySQL 5.7 runtime.' }
$php = (Get-Command $PhpExecutable -ErrorAction Stop).Source
# Never attach tests to an occupied port or an existing hosted/local database.
$probe = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
try { $probe.Start() } finally { $probe.Stop() }
$run = Join-Path ([System.IO.Path]::GetTempPath()) ('wellness-mysql57-sql-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $run | Out-Null
$data = Join-Path $run 'data'
$arguments = @('--no-defaults', ('--basedir="' + $runtime + '"'), ('--datadir="' + $data + '"'), '--log_syslog=0', '--explicit_defaults_for_timestamp=1', '--console')
$previous = @{}
foreach ($name in @('RECURRING_TEST_ALLOW_CREATE','RECURRING_TEST_PORT','RECURRING_TEST_USER','RECURRING_TEST_PASSWORD')) {
    $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
$process = $null
try {
    # No Windows service, registry changes, production configuration or private .env.
    $initialize = Start-Process -FilePath $server -ArgumentList ($arguments + '--initialize-insecure') -PassThru -WindowStyle Hidden -RedirectStandardOutput (Join-Path $run 'initialize.stdout.log') -RedirectStandardError (Join-Path $run 'initialize.stderr.log')
    $initialize.WaitForExit()
    if ($initialize.ExitCode -ne 0) { throw "MySQL initialization failed; inspect $run" }
    $process = Start-Process -FilePath $server -ArgumentList ($arguments + @("--port=$Port", '--bind-address=127.0.0.1')) -PassThru -WindowStyle Hidden -RedirectStandardOutput (Join-Path $run 'server.stdout.log') -RedirectStandardError (Join-Path $run 'server.stderr.log')
    $ready = $false
    for ($attempt = 0; $attempt -lt 100; $attempt++) {
        if ($process.HasExited) { throw "MySQL exited before readiness; inspect $run" }
        $socket = [System.Net.Sockets.TcpClient]::new()
        try { $socket.Connect('127.0.0.1', $Port); $ready = $true } catch { } finally { $socket.Dispose() }
        if ($ready) { break }
        Start-Sleep -Milliseconds 200
    }
    if (-not $ready) { throw "MySQL readiness timed out; inspect $run" }
    $env:RECURRING_TEST_ALLOW_CREATE = 'true'
    $env:RECURRING_TEST_PORT = [string]$Port
    $env:RECURRING_TEST_USER = 'root'
    $env:RECURRING_TEST_PASSWORD = ''
    & $php (Join-Path $repo "api/tests/integration/$IntegrationTest")
    if ($LASTEXITCODE -ne 0) { throw "MySQL SQL acceptance failed: $IntegrationTest" }
} finally {
    if ($null -ne $process -and -not $process.HasExited) {
        & (Join-Path $runtime 'bin/mysqladmin.exe') --no-defaults --host=127.0.0.1 "--port=$Port" --user=root shutdown 2>$null
        if (-not $process.WaitForExit(10000)) { Stop-Process -Id $process.Id; $process.WaitForExit(10000) | Out-Null }
    }
    foreach ($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
    Write-Output "Server stopped; synthetic data and logs retained at $run"
}
