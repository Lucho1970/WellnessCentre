<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Service\ClinicManagementService as C;
use Wellness\Service\PublicRuntimeConfig;
use Wellness\Http\ApiException;
$checks=0;
$assert=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('Clinic validation assertion failed');$checks++;};
$deny=function(array $input,string $field)use($assert){try{C::input($input);}catch(ApiException $e){$assert($e->status===422 && isset($e->fields[$field]));return;}throw new RuntimeException('Unsafe clinic input accepted');};
$valid=['name'=>'Separate Clinic','portal_host'=>'LIVINLIVELY.COPIHUE.CA.','website_url'=>'https://livinlively.ca'];
$result=C::input($valid);$assert($result['portal_host']==='livinlively.copihue.ca');$assert($result['name']==='Separate Clinic');
foreach (['name','portal_host','website_url'] as $field) $deny(array_replace($valid,[$field=>['invalid']]),$field);
foreach(['','a','https://a.test','a.test/path','a.test:443','a.test@b.test','a..test','127.0.0.1'] as $host)$deny(array_replace($valid,['portal_host'=>$host]),'portal_host');
foreach(['',str_repeat('x',161)] as $name)$deny(array_replace($valid,['name'=>$name]),'name');
foreach(['http://site.test','https://user:password@site.test','https://site.test/?secret=value','https://site.test/#x'] as $url)$deny(array_replace($valid,['website_url'=>$url]),'website_url');
$assert(PublicRuntimeConfig::fromEnvironment('https://one-clinic.test')['publicWebsiteUrl']==='https://one-clinic.test/');
echo "$checks clinic input validation checks passed.\n";
