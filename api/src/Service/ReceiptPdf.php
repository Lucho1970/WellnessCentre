<?php
declare(strict_types=1);
namespace Wellness\Service;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Only escaped receipt data and fixed markup enter the renderer. No external resources. */
final class ReceiptPdf
{
    public static function render(array $receipt): string
    {
        $fr=($receipt['language']??'en')==='fr';
        $e=static fn($value): string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $labels=$fr?['Reçu de paiement','Numéro','Émis le','Émetteur','Client','Praticien traitant','Désignation professionnelle','Numéro d’inscription','Service','Date du service','Durée en minutes','Mode de prestation','Frais de service','Frais de déplacement','Taxes','Total payé','Solde','Date du paiement','Mode de paiement']:['Payment receipt','Number','Issued on','Issuer','Client','Treating practitioner','Professional designation','Registration number','Service','Service date','Duration in minutes','Delivery mode','Service charge','Travel charge','Tax','Total paid','Balance','Payment date','Payment method'];
        $values=[$receipt['number'],$receipt['issued_at'],$receipt['issuer_name']."\n".$receipt['issuer_address']."\n".$receipt['issuer_contact'],$receipt['client_name'],$receipt['practitioner_name'],$receipt['designation'],$receipt['registration_number']?:($fr?'Sans objet':'Not applicable'),$receipt['service_name'],$receipt['service_date'].' ('.$receipt['timezone'].')',$receipt['duration_minutes'],$receipt['delivery_mode']==='mobile'?($fr?'Chez le client':'On-Site'):($fr?'En clinique':'In clinic')];
        $logos='';foreach(['clinic_logo','practitioner_logo'] as $key)if(!empty($receipt[$key]))$logos.='<img style="max-width:150px;max-height:65px;margin-right:20px" src="'.$e($receipt[$key]).'">';
        $html='<html><head><meta charset="utf-8"><style>@page{margin:42pt}body{font:11px "DejaVu Sans";color:#2e2e2e}h1{font-size:24px;color:#0f4c5c}td{padding:6px 8px;vertical-align:top;overflow-wrap:break-word}table{width:100%;border-collapse:collapse}td:first-child{width:35%;font-weight:bold}tr:nth-child(even){background:#f8f9fa}.note{margin-top:22px;font-size:9px}</style></head><body>'.$logos.'<h1>'.$e($labels[0]).'</h1><table>';
        foreach($values as $i=>$value)$html.='<tr><td>'.$e($labels[$i+1]).'</td><td>'.nl2br($e($value)).'</td></tr>';
        $money=static fn(int $cents): string=>number_format($cents/100,2,'.',',').' CAD';
        foreach(['base_cents','travel_cents','tax_cents','total_cents','balance_cents','paid_on','method_label'] as $i=>$key){
            $value=$receipt[$key];if(str_ends_with($key,'_cents'))$value=$money((int)$value);
            $html.='<tr><td>'.$e($labels[12+$i]).'</td><td>'.$e($value).'</td></tr>';
        }
        $html.='</table><p class="note">'.$e($fr?'Paiement enregistré manuellement. Le remboursement dépend de votre couverture et de la décision de votre assureur.':'Payment recorded manually. Reimbursement depends on your coverage and your insurer’s decision.').'</p></body></html>';
        $options=new Options(['defaultFont'=>'DejaVu Sans','isRemoteEnabled'=>false,'isPhpEnabled'=>false,'isJavascriptEnabled'=>false]);
        $pdf=new Dompdf($options);$pdf->setPaper('letter');$pdf->loadHtml($html,'UTF-8');$pdf->render();
        return $pdf->output();
    }
}
