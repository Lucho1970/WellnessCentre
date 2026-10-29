<?php
declare(strict_types=1);
// Exercise the real PHP input stream on localhost, with no database, identity or provider.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
$router = tempnam(sys_get_temp_dir(), 'wellness-body-');
$log = $router.'.log';
$process = null;
$pipes = [];
try {
    file_put_contents($router, '<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).';'. <<<'PHP'
try {
    $request = Wellness\Http\Request::capture();
    Wellness\Http\Response::json(['accepted'=>true,'bytes'=>strlen($request->body['p']??''),'method'=>$request->method,'path'=>$request->path,'query'=>$request->query,'correlation'=>$request->correlationId,'bearer'=>$request->bearerToken(),'content_length'=>$_SERVER['CONTENT_LENGTH']??null]);
} catch (Wellness\Http\ApiException $error) {
    Wellness\Http\Response::json(['error'=>['code'=>$error->errorCode,'message'=>$error->getMessage()]],$error->status);
}
PHP);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) throw new RuntimeException('Local test listener unavailable.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', $address, $router], [['pipe','r'],['file',$log,'a'],['file',$log,'a']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Local PHP test server unavailable.');
    fclose($pipes[0]);
    $call = static function (string $path, string $raw = '', string $method = 'POST', bool $chunked = false) use ($address): array {
        $curl = curl_init('http://'.$address.$path);
        $headers = ['Content-Type: application/json', 'Expect:', 'X-Correlation-ID: synthetic-body-test', 'Authorization: Bearer synthetic-test-token'];
        if ($chunked) $headers[] = 'Transfer-Encoding: chunked';
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_POSTFIELDS=>$raw, CURLOPT_HTTPHEADER=>$headers, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_PROXY=>'']);
        $out = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return [$status, is_string($out) ? json_decode($out, true) : null];
    };
    $ready = false;
    for ($i=0; $i<50; $i++) {
        if ($call('/ready')[0] === 200) { $ready = true; break; }
        usleep(100000);
    }
    $check($ready, 'Local request server starts.');
    $payload = static fn(int $bytes): string => '{"p":"'.str_repeat('x', $bytes - 8).'"}';
    foreach ([
        ['/api/v1/clients',2097152], ['/api/v1/forms/drafts/import',2097152],
        ['/api/v1/admin/service-imports/preview',1048576],
        ['/api/v1/admin/service-imports/practitioner-applications',65536],
        ['/api/v1/customer/forms/1',65536], ['/api/v1/public/practitioner-enquiries',65536],
        ['/api/v1/travel-enquiries/1',65536], ['/api/v1/profile/discovery-preferences',65536],
        ['/api/v1/application/billing/plans',65536], ['/api/v1/practitioner-applications/me',65536],
        ['/api/v1/application/provider-evidence/preview',65536],
        ['/api/v1/application/clinics/3/communications/settings',65536],
    ] as [$path,$limit]) {
        [$status,$data] = $call($path, $payload($limit));
        $check($status === 200 && $data['bytes'] === $limit - 8, 'Exact byte limit is accepted: '.$path);
        [$status,$data] = $call($path.'?limit=999999999', $payload($limit+1));
        $check($status === 413 && $data['error']['code'] === 'request_too_large' && !isset($data['accepted']), 'One extra byte is rejected before dispatch: '.$path);
    }
    foreach (['GET','POST','PUT','PATCH','DELETE','OPTIONS'] as $method) {
        [$status,$data] = $call('/unregistered-path', $payload(2097153), $method);
        $check($status === 413 && $data['error']['code'] === 'request_too_large', 'Unknown route/method cannot bypass the fallback: '.$method);
    }
    foreach (['', '{}', '[]', '{"p":"é français"}'] as $raw) {
        [$status] = $call('/api/v1/clients', $raw);
        $check($status === 200, 'Existing empty, object, list and UTF-8 JSON remain accepted.');
    }
    foreach (['{', 'null', 'false', '0', '123', '"string"', '{"p":"'."\xff".'"}'] as $raw) {
        [$status,$data] = $call('/api/v1/clients', $raw);
        $check($status === 400 && $data['error']['code'] === 'invalid_json', 'Malformed/scalar JSON remains rejected.');
    }
    [$status,$data] = $call('/api/v1/clients', str_repeat('{',2097153));
    $check($status === 413 && $data['error']['code'] === 'request_too_large', 'Oversize invalid JSON is rejected by size before JSON parsing.');
    [$status,$data] = $call('/api/v1/clients?synthetic=1', '{"p":"small"}', 'PATCH', true);
    $check($status === 200 && $data['content_length'] === null && $data['bytes'] === 5, 'Chunked input without Content-Length is accepted within limit.');
    $check($data['method'] === 'PATCH' && $data['path'] === '/api/v1/clients' && $data['query'] === ['synthetic'=>'1'] && $data['correlation'] === 'synthetic-body-test' && $data['bearer'] === 'synthetic-test-token', 'Capture preserves routing, query, correlation and bearer behavior.');
    [$status,$data] = $call('/api/v1/customer/forms/1', $payload(65537), 'POST', true);
    $check($status === 413 && $data['error']['code'] === 'request_too_large', 'Chunked input cannot bypass the stricter policy.');
    [$status,$data] = $call('/api/v1/clients', $payload(2097153), 'POST', true);
    $check($status === 413 && $data['error']['code'] === 'request_too_large', 'Chunked input cannot bypass the fallback.');
    $check(!str_contains(json_encode($data), str_repeat('x',32)) && !str_contains(json_encode($data), 'synthetic-test-token'), 'Oversize error excludes body and credentials.');
    // These are capture compatibility checks; service-level format/permission validators still run later.
    foreach ([['/api/v1/admin/clinic/branding/logo',1100000],['/api/v1/profile/avatar',750000],['/api/v1/receipts/template',420000]] as [$path,$bytes]) {
        [$status,$data] = $call($path, $payload($bytes));
        $check($status === 200 && $data['bytes'] === $bytes-8, 'Existing encoded-image envelope still fits: '.$path);
    }
    echo "Request-body HTTP limits: $checks checks passed; synthetic localhost only.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach ([$router,$log] as $file) if (is_file($file)) unlink($file);
}
