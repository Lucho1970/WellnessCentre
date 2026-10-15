<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Wellness\Auth\AuthContext;
use Wellness\Http\ApiException;
use Wellness\Service\{ReceiptPdf,SessionReceiptService};
set_error_handler(static function(int $severity,string $message,string $file,int $line): bool {if(!(error_reporting()&$severity))return false;throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;$assert=function(bool $ok)use(&$checks){if(!$ok)throw new RuntimeException('Receipt policy assertion failed.');$checks++;};
$deny=function(callable $call)use($assert){try{$call();}catch(ApiException){$assert(true);return;}throw new RuntimeException('Receipt policy accepted invalid input.');};
$body=['issuer_name'=>'Clinic','issuer_address'=>'Test address','issuer_contact'=>'test@example.test','designation'=>'RMT','registration_number'=>'TEST','idempotency_key'=>'synthetic-key-0001','version'=>1,'tax_cents'=>1300,'amount_cents'=>11300,'method'=>'cash','issuer_type'=>'clinic','confirmed'=>true,'paid_on'=>'2026-01-02'];
$assert(SessionReceiptService::validated($body)['amount_cents']===11300);
foreach([['tax_cents'=>-1],['amount_cents'=>1.1],['amount_cents'=>'11300'],['method'=>'insurance'],['paid_on'=>'2026-02-30'],['confirmed'=>false],['issuer_name'=>"bad\nheader"],['idempotency_key'=>'short']] as $change)$deny(fn()=>SessionReceiptService::validated(array_replace($body,$change)));
$row=['clinic_id'=>1,'client_id'=>8,'practitioner_user_id'=>2];
foreach([new AuthContext(8,1,'','','','client',[]),new AuthContext(2,1,'','','','staff',['practitioner']),new AuthContext(1,1,'','','','staff',['clinic_admin'])] as $actor){SessionReceiptService::assertAccess($actor,$row);$checks++;}
foreach([new AuthContext(9,1,'','','','client',[]),new AuthContext(8,2,'','','','client',[]),new AuthContext(3,1,'','','','staff',['practitioner']),new AuthContext(2,1,'','','','staff',['reception'])] as $actor)$deny(fn()=>SessionReceiptService::assertAccess($actor,$row));
foreach(['https://example.test/logo.png','data:image/svg+xml;base64,PHN2Zz4=','data:image/jpeg;base64,YmFk'] as $logo)$deny(fn()=>SessionReceiptService::logo($logo));
$s=$body+['number'=>'R-SYNTHETIC','issued_at'=>'2026-01-02 16:00 UTC','client_name'=>'Client Émilie','practitioner_name'=>'Élodie Test','service_name'=>'Massage thérapeutique','service_date'=>'2026-01-02 10:00','timezone'=>'America/Toronto','duration_minutes'=>60,'delivery_mode'=>'mobile','base_cents'=>10000,'travel_cents'=>0,'total_cents'=>11300,'balance_cents'=>0,'method_label'=>'Espèces','language'=>'fr'];
if(extension_loaded('gd')){
    $image=imagecreatetruecolor(240,70);imagefill($image,0,0,imagecolorallocate($image,15,76,92));imagestring($image,5,18,25,'CLINIC LOGO',imagecolorallocate($image,255,255,255));ob_start();imagepng($image);$png=ob_get_clean();
    $s['clinic_logo']='data:image/png;base64,'.base64_encode($png);$assert(SessionReceiptService::logo($s['clinic_logo'])===$s['clinic_logo']);
    ob_start();imagejpeg($image);$jpeg=ob_get_clean();$s['practitioner_logo']='data:image/jpeg;base64,'.base64_encode($jpeg);$assert(SessionReceiptService::logo($s['practitioner_logo'])===$s['practitioner_logo']);
}
$pdf=ReceiptPdf::render($s);$assert(str_starts_with($pdf,'%PDF-'));file_put_contents(dirname(__DIR__,2).'/.tmp/session-receipt-fr.pdf',$pdf);
echo "$checks receipt validation and authorization checks passed.\n";
