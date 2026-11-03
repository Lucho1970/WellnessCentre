<?php
declare(strict_types=1);
// Private CLI entry point: refuse HTTP before loading any private configuration.
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
use Wellness\{Config,Database};
use Wellness\Service\{CommitSafeEmail,GraphMailClient};
require dirname(__DIR__).'/vendor/autoload.php';
Config::loadEnvFile(dirname(__DIR__).'/.env');
try {
    CommitSafeEmail::requireConfigured();
    $options=getopt('',['limit::']);
    $limit=isset($options['limit'])?filter_var($options['limit'],FILTER_VALIDATE_INT):20;
    if ($limit===false || $limit<1 || $limit>100) throw new InvalidArgumentException('Invalid batch limit.');
    $env=static fn(string $key): string=>trim((string)($_ENV[$key]??getenv($key)?:''));
    $mail=new GraphMailClient($env('MAIL_TENANT_ID'),$env('MAIL_CLIENT_ID'),$env('MAIL_CLIENT_SECRET'),$env('MAIL_FROM_ADDRESS'));
    $result=(new CommitSafeEmail((new Database(Config::fromEnvironment()))->connection()))->run($mail,$limit);
    fwrite(STDOUT,json_encode($result,JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $error) {
    // Do not print provider URLs, SQL, payloads or secret configuration to scheduler logs.
    fwrite(STDERR,'Account email worker failed: '.$error::class."\n");exit(1);
}
