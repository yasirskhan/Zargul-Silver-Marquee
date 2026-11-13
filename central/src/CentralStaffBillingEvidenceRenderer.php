<?php
declare(strict_types=1);
namespace Hafzatech\Central;

/** Escaped billing evidence review UI. A review is never settlement proof. */
final class CentralStaffBillingEvidenceRenderer
{
    public static function html(array $rows,string $customerId,string $csrf):string
    {
        $e=static fn(mixed $v):string=>htmlspecialchars(
            is_scalar($v)?(string)$v:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $proof=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $body='';
        foreach($rows as $row){
            $evidence=(string)($row['evidence_id']??'');
            if(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$evidence)!==1)continue;
            $body.='<tr><td>'.$e($row['invoice_number']??'').'</td><td>'
                .$e($row['currency']??'').' '.$e($row['total_minor']??'').'</td><td>'
                .$e($row['claimed_reference']??'').'</td><td>'
                .'<form method="post" action="/staff/billing/payment-evidence/decide">'
                .'<input type="hidden" name="staff_csrf" value="'.$proof.'">'
                .'<input type="hidden" name="customer_id" value="'.$e($customerId).'">'
                .'<input type="hidden" name="evidence_id" value="'.$e($evidence).'">'
                .'<label>Decision <select name="decision"><option value="reviewed">Reviewed claim</option>'
                .'<option value="rejected">Reject claim</option></select></label>'
                .'<label>Reason <input name="reason" minlength="12" maxlength="400" required></label>'
                .'<button type="submit">Record evidence decision</button></form></td></tr>';
        }
        if($body==='')$body='<tr><td colspan="4">No pending payment claims.</td></tr>';
        return '<section aria-label="Private HAFZATECH payment evidence queue">'
            .'<h2>Customer payment claims</h2>'
            .'<p>Reviewing a claim does not verify settlement. This decision changes only the '
            .'customer-claim state and never marks an invoice paid.</p>'
            .'<table><thead><tr><th>Invoice</th><th>Amount</th><th>Claimed reference</th>'
            .'<th>Review</th></tr></thead><tbody>'.$body.'</tbody></table></section>';
    }

    public static function receipt(array $result):string
    {
        $decision=(string)($result['decision']??'');
        if(!in_array($decision,['reviewed','rejected'],true)) {
            throw new RegistrationError('invalid_payment_evidence_review_receipt');
        }
        return '<section aria-label="Private HAFZATECH payment evidence receipt">'
            .'<h2>Payment evidence decision recorded</h2>'
            .'<p>Claim decision: '.htmlspecialchars($decision,ENT_QUOTES).'. '
            .'This is not independently verified settlement and does not mark the invoice paid.</p>'
            .'</section>';
    }
}