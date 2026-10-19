<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Private allowlisted display for a newly issued vendor invoice only.
 * No receiver instructions, settlement proof, session tokens or financial
 * entitlements are ever rendered. This receipt is NOT payment confirmation.
 */
final class CentralStaffVendorInvoiceReceiptRenderer
{
    /** @param array<string,mixed> $invoice */
    public static function render(array $invoice):string
    {
        $keys=array_keys($invoice);
        sort($keys,SORT_STRING);
        if ($keys!==['currency','invoice_id','invoice_number','status',
                    'term_months','total_minor'] ||
            !is_string($invoice['invoice_id']) ||
            preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
                $invoice['invoice_id'])!==1 ||
            !is_string($invoice['invoice_number']) ||
            preg_match('/^HTZ-[A-F0-9]{32}$/D',$invoice['invoice_number'])!==1 ||
            !is_string($invoice['currency']) ||
            preg_match('/^[A-Z]{3}$/D',$invoice['currency'])!==1 ||
            !is_string($invoice['total_minor']) ||
            preg_match('/^[0-9]{1,18}$/D',$invoice['total_minor'])!==1 ||
            !in_array($invoice['term_months'],[6,12],true) ||
            !in_array($invoice['status'],['issued','void'],true)) {
            throw new RegistrationError('invalid_vendor_invoice_receipt');
        }
        $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return '<section aria-label="Private HAFZATECH vendor invoice receipt">'.
            '<h2>HAFZATECH subscription invoice</h2>'.
            '<dl><dt>Invoice number</dt><dd>'.$e($invoice['invoice_number']).'</dd>'.
            '<dt>Invoice ID</dt><dd>'.$e($invoice['invoice_id']).'</dd>'.
            '<dt>Term</dt><dd>'.(int)$invoice['term_months'].' months</dd>'.
            '<dt>Currency</dt><dd>'.$e($invoice['currency']).'</dd>'.
            '<dt>Total (minor units)</dt><dd>'.$e($invoice['total_minor']).'</dd>'.
            '<dt>Invoice status</dt><dd>'.$e($invoice['status']).'</dd></dl>'.
            '<p>Invoice issuance is NOT verified payment, signed licensing, or activation.</p>'.
            '<p>No receiving-bank credentials, payment evidence or signing keys are displayed.</p>'.
            '</section>';
    }
}
