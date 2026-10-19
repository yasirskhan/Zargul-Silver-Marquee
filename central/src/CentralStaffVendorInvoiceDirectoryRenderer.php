<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/** Escaped private staff-only invoice index. Never emits bank or session data. */
final class CentralStaffVendorInvoiceDirectoryRenderer
{
    /** @param array<int,array<string,mixed>> $rows */
    public static function render(array $rows,string $customerId,string $csrf):string
    {
        if(count($rows)>25)throw new RegistrationError('invalid_invoice_directory_response');
        $e=static fn(mixed $s):string=>htmlspecialchars(
            is_scalar($s)?(string)$s:'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        if(preg_match('/^[a-f0-9-]{36}$/D',$customerId)!==1 ||
            preg_match('/^[a-f0-9]{64}$/D',$csrf)!==1) {
            throw new RegistrationError('invalid_invoice_directory_response');
        }
        $body='';
        foreach($rows as $row) {
            $body.='<tr><td>'.$e($row['invoice_number']??'').'</td>'.
                '<td>'.$e($row['status']??'').'</td>'.
                '<td>'.$e($row['term_months']??'').' months</td>'.
                '<td>'.$e($row['currency']??'').' '.$e($row['total_minor']??'').
                ' minor units</td>'.
                '<td>'.$e($row['issued_at']??'').'</td>'.
                '<td><form method="post" action="/staff/billing/overview">'.
                '<input type="hidden" name="staff_csrf" value="'.$e($csrf).'">'.
                '<input type="hidden" name="customer_id" value="'.$e($customerId).'">'.
                '<input type="hidden" name="invoice_id" value="'.
                $e($row['invoice_id']??'').'">'.
                '<button type="submit">View verified billing status</button>'.
                '</form></td></tr>';
        }
        if($body==='') $body='<tr><td colspan="6">No invoices recorded for this customer.</td></tr>';
        return '<section aria-label="Private HAFZATECH vendor invoices">'.
            '<h2>Customer subscription invoices</h2>'.
            '<p>Issued is NOT paid. Independent settlement verification and signed license issuance are separate.</p>'.
            '<table><thead><tr><th>Invoice</th><th>Status</th><th>Term</th>'.
            '<th>Amount</th><th>Issued</th><th>Review</th></tr></thead><tbody>'.
            $body.'</tbody></table></section>';
    }
}
