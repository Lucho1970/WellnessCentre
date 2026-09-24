<?php
declare(strict_types=1);

/** Temporary public entry point. Upload only as /public_html/wellness/api/test-suite.php. */
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

if ((($_SERVER['HTTPS'] ?? '') !== 'on') && (int)($_SERVER['SERVER_PORT'] ?? 0) !== 443) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 3) . '/wellness-api';
if (!is_file($root . '/.env') || !is_file($root . '/vendor/autoload.php') || !is_file($root . '/tests/hosted/Suite.php')) {
    http_response_code(404);
    exit;
}
require $root . '/vendor/autoload.php';
require $root . '/tests/hosted/Suite.php';
\Wellness\Config::loadEnvFile($root . '/.env');
$env = static fn(string $key): string => trim((string)($_ENV[$key] ?? getenv($key) ?: ''));
$secret = $env('HOSTED_TEST_SECRET');
if ($env('HOSTED_TEST_ENABLED') !== 'true' || strlen($secret) < 32
    || $env('HOSTED_TEST_DB_ACK') === '' || !hash_equals($env('DB_NAME'), $env('HOSTED_TEST_DB_ACK'))) {
    http_response_code(404);
    exit;
}
$apiBase = rtrim($env('HOSTED_TEST_API_BASE'), '/');
$urlParts = parse_url($apiBase);
if (!is_array($urlParts) || ($urlParts['scheme'] ?? '') !== 'https' || empty($urlParts['host'])
    || ($urlParts['path'] ?? '') !== '/api' || isset($urlParts['user']) || isset($urlParts['pass'])
    || isset($urlParts['query']) || isset($urlParts['fragment'])) {
    http_response_code(503);
    exit('Hosted test API base is not configured as an HTTPS /api URL.');
}

// Signed, same-endpoint HTTP workers bypass the browser session lock. They may
// operate only on the test harness's synthetic clinic, never a real clinic.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_SERVER['HTTP_X_WELLNESS_TEST_SIGNATURE'])) {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $stamp = (string)($_SERVER['HTTP_X_WELLNESS_TEST_TIMESTAMP'] ?? '');
    $signature = (string)$_SERVER['HTTP_X_WELLNESS_TEST_SIGNATURE'];
    require $root . '/tests/hosted/HttpBookingWorker.php';
    if ($raw === false || strlen($raw) > 4096 || !\Wellness\Tests\Hosted\HttpBookingWorker::authorized($secret, $stamp, $signature, $raw)) {
        http_response_code(404);
        exit;
    }
    try {
        $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $result = is_array($input)
            ? \Wellness\Tests\Hosted\HttpBookingWorker::run(\Wellness\Config::fromEnvironment(), $input)
            : ['ok' => false, 'status' => 400];
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } catch (\Throwable $error) {
        error_log('Hosted booking worker endpoint failed: ' . get_class($error));
        http_response_code(503);
        echo json_encode(['ok' => false, 'unexpected' => 'worker_error']);
    }
    exit;
}

session_name('wellness_hosted_test_suite');
session_set_cookie_params(['lifetime' => 0, 'path' => $_SERVER['SCRIPT_NAME'] ?? '/api/test-suite.php', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
if (!session_start()) {
    http_response_code(503);
    exit('PHP sessions are unavailable.');
}
$authenticated = hash_equals(hash_hmac('sha256', session_id(), $secret), (string)($_SESSION['auth'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!hash_equals($secret, (string)($_POST['secret'] ?? ''))) {
        http_response_code(404);
        exit;
    }
    session_regenerate_id(true);
    $_SESSION['auth'] = hash_hmac('sha256', session_id(), $secret);
    $_SESSION['csrf'] = bin2hex(random_bytes(20));
    header('Location: ' . ($_SERVER['SCRIPT_NAME'] ?? '/api/test-suite.php'), true, 303);
    exit;
}
if (!$authenticated) {
    if ($method !== 'GET') { http_response_code(404); exit; }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hosted test suite</title>';
    echo '<main style="max-width:38rem;margin:3rem auto;font:1rem system-ui;padding:1rem"><h1>Hosted development tests</h1>';
    echo '<p>Use only on the development deployment, after backing up the database. The complete suite creates synthetic records in a separate clinic.</p>';
    echo '<form method="post"><input type="hidden" name="action" value="login"><label>Temporary test secret <input type="password" name="secret" required autocomplete="off"></label> <button>Open tests</button></form></main>';
    exit;
}
if ($method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid session request.']);
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action !== 'run') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action.']);
        exit;
    }
    $id = (string)($_POST['id'] ?? '');
    if (!isset(\Wellness\Tests\Hosted\Suite::cases()[$id])) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown test case.']);
        exit;
    }
    if ($id === 'booking-race' && ($_POST['synthetic_ack'] ?? '') !== 'yes') {
        http_response_code(400);
        echo json_encode(['error' => 'Synthetic booking test was not confirmed.']);
        exit;
    }
    try {
        $config = \Wellness\Config::fromEnvironment();
        $phpCli = $env('HOSTED_TEST_PHP_CLI') ?: PHP_BINARY;
        if (function_exists('set_time_limit')) @set_time_limit($id === 'booking-race' ? 120 : 35);
        $result = \Wellness\Tests\Hosted\Suite::run($id, $config, $apiBase, $phpCli, $secret);
        echo json_encode(['id' => $id] + $result, JSON_THROW_ON_ERROR);
    } catch (\Throwable $error) {
        error_log('Hosted suite endpoint failed: ' . get_class($error) . ': ' . $error->getMessage());
        http_response_code(503);
        echo json_encode(['id' => $id, 'status' => 'failed', 'detail' => 'Server error. Check the private PHP error log.']);
    }
    exit;
}
if ($method !== 'GET') { http_response_code(405); exit; }

header('Content-Type: text/html; charset=utf-8');
$cases = \Wellness\Tests\Hosted\Suite::cases();
$csrf = (string)$_SESSION['csrf'];
echo '<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hosted test suite</title>';
echo '<style nonce="' . $nonce . '">body{font:1rem system-ui;margin:2rem auto;max-width:70rem;padding:0 1rem;color:#172c35}table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ccd4d8;padding:.55rem;text-align:left;vertical-align:top}button{padding:.55rem .8rem;margin:.25rem;cursor:pointer}.passed{color:#176a3c}.failed{color:#a52929}.running{color:#7b5900}pre{white-space:pre-wrap;overflow-wrap:anywhere}small{color:#52626a}</style>';
echo '<main><h1>Hosted development test suite</h1><p>Tests run one at a time and report results here. The booking race writes synthetic clinic records; other checks use fixtures, read-only queries, or GET requests.</p>';
echo '<p><button id="safe">Run non-booking checks</button> <button id="all">Run complete suite</button> <button id="logout">Sign out</button></p>';
echo '<label><input type="checkbox" id="ack"> I backed up the development database and authorize synthetic booking records.</label>';
echo '<p id="summary" role="status">Ready. Browser and separate-scratch-database tests are not included.</p>';
echo '<table><thead><tr><th>Group</th><th>Test</th><th>Result</th><th>Detail</th></tr></thead><tbody>';
foreach ($cases as $id => $case) {
    echo '<tr data-id="' . htmlspecialchars($id, ENT_QUOTES) . '"><td>' . htmlspecialchars($case['group'], ENT_QUOTES) . '</td><td>' . htmlspecialchars($case['label'], ENT_QUOTES) . '</td><td class="status">Pending</td><td class="detail"></td></tr>';
}
echo '</tbody></table><p><small>Not covered here: Playwright browser journeys, external identity providers, real email delivery, and integration tests that require a new scratch database.</small></p></main>';
$client = ['ids' => array_keys($cases), 'csrf' => $csrf];
echo '<script nonce="' . $nonce . '">const cfg=' . json_encode($client, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . ';';
echo <<<'JS'
const summary=document.getElementById('summary');
let busy=false;
async function run(ids){
  if(busy)return;
  busy=true;
  document.querySelectorAll('button').forEach(b=>b.disabled=true);
  let passed=0,failed=0;
  for(const id of ids){
    const row=document.querySelector(`tr[data-id="${id}"]`);
    const status=row.querySelector('.status'),detail=row.querySelector('.detail');
    status.textContent='Running';status.className='status running';detail.textContent='';
    summary.textContent=`Running ${id}… ${passed} passed, ${failed} failed.`;
    try{
      const body=new URLSearchParams({action:'run',id,csrf:cfg.csrf,synthetic_ack:document.getElementById('ack').checked?'yes':'no'});
      const response=await fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
      const result=await response.json();
      if(result.status==='passed'){passed++;status.textContent='Passed';status.className='status passed';}
      else if(result.status==='skipped'){status.textContent='Skipped';status.className='status';}
      else{failed++;status.textContent='Failed';status.className='status failed';}
      detail.textContent=(result.detail||result.error||'No result')+(result.seconds===undefined?'':` (${result.seconds}s)`);
    }catch(error){failed++;status.textContent='Failed';status.className='status failed';detail.textContent='Request failed or timed out.';}
  }
  summary.textContent=`Finished: ${passed} passed, ${failed} failed, ${cfg.ids.length-ids.length} not run.`;
  document.querySelectorAll('button').forEach(b=>b.disabled=false);
  busy=false;
}
document.getElementById('safe').onclick=()=>run(cfg.ids.filter(id=>id!=='booking-race'));
document.getElementById('all').onclick=()=>{
  if(!document.getElementById('ack').checked){summary.textContent='Back up and confirm synthetic booking records first.';return;}
  run(cfg.ids);
};
document.getElementById('logout').onclick=async()=>{
  await fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'logout',csrf:cfg.csrf})});
  location.reload();
};
JS;
echo '</script></html>';
