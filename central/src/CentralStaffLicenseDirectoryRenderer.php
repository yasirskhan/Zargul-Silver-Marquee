<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/** Escaped customer license directory with optional renewal preparation. */
final class CentralStaffLicenseDirectoryRenderer
{
    public static function html(array $rows,string $customerId,
        ?string $csrf=null,array $invoices=[]): string
    {
        $e=static fn(mixed $v):string=>htmlspecialchars(
            is_scalar($v)?(string)$v:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $body='';
        foreach($rows as $row){
            $expiry=is_string($row['expires_at']??null)&&$row['expires_at']!==''
                ? $e($row['expires_at']) : 'Not yet signed';
            $renewal='<span>Renewal unavailable</span>';
            $installation=(string)($row['installation_id']??'');
            if(is_string($csrf)&&preg_match('/^[a-f0-9]{64}$/D',$csrf)===1
                && preg_match('/^[a-f0-9-]{36}$/D',$installation)===1 && $invoices!==[]){
                $options='';
                foreach($invoices as $invoice){
                    $id=(string)($invoice['id']??'');
                    if(preg_match('/^[a-f0-9-]{36}$/D',$id)!==1)continue;
                    $eligible=(int)($invoice['settlement_eligible']??0)===1;
                    $options.='<option value="'.$e($id).'"'.($eligible?'':' disabled').'>'
                        .$e($invoice['invoice_number']??$id).' · '.$e($invoice['status']??'').' · '
                        .($eligible?'settlement eligible':'not settlement eligible').'</option>';
                }
                if($options!==''){
                    $renewal='<form method="post" action="/staff/licensing/prepare">'
                        .'<input type="hidden" name="staff_csrf" value="'.$e($csrf).'">'
                        .'<input type="hidden" name="customer_id" value="'.$e($customerId).'">'
                        .'<input type="hidden" name="installation_id" value="'.$e($installation).'">'
                        .'<label>Eligible invoice <select name="invoice_id">'.$options.'</select></label>'
                        .'<button type="submit">Prepare paid renewal</button></form>';
                }
            }
            $body.='<tr><td>'.$e($row['approved_domain']??'').'</td><td>'
                .$e($row['installation_status']??'').'</td><td>'
                .$e($row['current_revision']??'').'</td><td>'.$e($row['term_months']??'').'</td><td>'
                .$expiry.'</td><td>'.$e($row['revision_count']??'').'</td><td>'
                .$e($row['pending_count']??'').'</td><td>'.$e($row['latest_revision_id']??'None')
                .'</td><td>'.$e($row['latest_revision_state']??'None').'</td><td>'
                .$e($row['latest_invoice_id']??'None').'</td><td>'.$renewal.'</td></tr>';
        }
        if($body==='')$body='<tr><td colspan="11">No licenses recorded for this customer.</td></tr>';
        return '<section aria-label="Private HAFZATECH customer licenses">'
            .'<h2>Customer licenses and renewals</h2>'
            .'<p>Renewal preparation requires an independently verified paid invoice. '
            .'Preparation creates an awaiting-isolated-signer revision only; it never signs, '
            .'delivers or activates a license.</p>'
            .'<table><thead><tr><th>Approved domain</th><th>Installation</th>'
            .'<th>Current revision</th><th>Term</th><th>Expires</th>'
            .'<th>Revisions</th><th>Awaiting signer</th><th>Latest revision</th>'
            .'<th>Revision state</th><th>Bound invoice</th><th>Renewal</th></tr></thead><tbody>'
            .$body.'</tbody></table>'
            .'<p><a href="/staff/customers/'.$e($customerId).'/billing">Customer billing</a> · '
            .'<a href="/staff/customers/'.$e($customerId).'/support">Support tickets</a></p></section>';
    }
}