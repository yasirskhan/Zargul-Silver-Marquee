<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Dependency-free, one-page PDF/1.4 internal invoice and receipt copies.
 * Source is the immutable issued invoice/payment; no live rates or contacts.
 * These are NOT approved tax/legal customer-delivery documents.
 */
final class VenueDocumentPdfService
{
    public function __construct(private readonly VenueInvoicePaymentService $payments) {}

    public function invoice(User $actor, string $companyId, string $invoiceId): string
    {
        [$invoice, $balance] = $this->authorizedInvoice($actor, $companyId, $invoiceId);
        $lines = [
            'INTERNAL INVOICE COPY - NOT AUTHORIZED FOR CUSTOMER DELIVERY',
            'Invoice: '.$invoice->invoice_number,
            'Reservation reference: '.$invoice->reservation_id,
            'Currency: '.$invoice->currency_code.' (all amounts in minor units)',
            'Issued on (UTC): '.$invoice->issued_at,
            '----- IMMUTABLE PRICING SNAPSHOT -----',
        ];
        foreach (json_decode($invoice->frozen_lines, true, 512, JSON_THROW_ON_ERROR) as $line) {
            $lines[] = (string) ($line['code'] ?? 'Item').' - qty '.(int) ($line['quantity'] ?? 0)
                .' x '.(int) ($line['rateMinor'] ?? 0)
                .' = '.(int) ($line['amountMinor'] ?? 0);
        }
        array_push($lines,
            'Subtotal: '.(int) $invoice->subtotal_minor,
            'Frozen tax (bps '.(int) $invoice->tax_rate_bps.'): '.(int) $invoice->tax_minor,
            'Invoice total: '.(int) $invoice->total_minor,
            'Net receipts after approved refunds: '.$balance['paidMinor'],
            'Outstanding balance: '.$balance['dueMinor'],
            'Reference: '.$invoice->id,
            'Document generated from immutable internal records. No delivery or payment.'
        );
        return $this->render($lines);
    }

    public function receipt(User $actor, string $companyId, string $invoiceId, string $receiptId): string
    {
        if (! Str::isUuid($receiptId)) {
            throw new InvalidArgumentException('Invalid receipt reference.');
        }
        [$invoice, $balance] = $this->authorizedInvoice($actor, $companyId, $invoiceId);
        $receipt = collect($balance['payments'])->firstWhere('id', $receiptId);
        if (! is_array($receipt)) {
            throw new AuthorizationException('Receipt is not accessible.');
        }
        $refunded = collect($balance['refunds'])->contains('paymentId', $receiptId);
        return $this->render([
            'INTERNAL RECEIPT COPY - NOT AUTHORIZED FOR CUSTOMER DELIVERY',
            'Receipt: '.$receipt['receiptNumber'],
            'Invoice: '.$invoice->invoice_number,
            'Reservation reference: '.$invoice->reservation_id,
            'Currency: '.$receipt['currency'].' (all amounts in minor units)',
            'Amount recorded: '.$receipt['amountMinor'],
            'Method: '.strtoupper($receipt['method']),
            'Bank reference: '.($receipt['reference'] ?: 'Not applicable'),
            'Refund state: '.($refunded ? 'Fully reversed under approved refund' : 'No recorded reversal'),
            'Original immutable journal: '.$receipt['journalId'],
            'Receipt reference: '.$receipt['id'],
            'This copy is NOT evidence of processor settlement or tax approval.',
        ]);
    }

    public function refund(User $actor, string $companyId, string $invoiceId, string $refundId): string
    {
        if (! Str::isUuid($refundId)) {
            throw new InvalidArgumentException('Invalid refund document reference.');
        }
        [$invoice, $balance] = $this->authorizedInvoice($actor, $companyId, $invoiceId);
        $refund = collect($balance['refunds'])->firstWhere('id', $refundId);
        if (! is_array($refund)) {
            throw new AuthorizationException('Approved refund record is not accessible.');
        }
        return $this->render([
            'INTERNAL REFUND COPY - NOT AUTHORIZED FOR CUSTOMER DELIVERY',
            'Approved refund: '.$refund['refundNumber'],
            'Original invoice: '.$invoice->invoice_number,
            'Reservation reference: '.$invoice->reservation_id,
            'Original payment reference: '.$refund['paymentId'],
            'Owner/delegated approval reference: '.$refund['approvalId'],
            'Reversed amount in minor units: '.$refund['amountMinor'],
            'Currency: '.$invoice->currency_code,
            'Balance after recorded receipts and approved refunds: '.$balance['dueMinor'],
            'Original receipts/approvals are preserved and never deleted.',
            'No bank transfer, processor settlement or customer delivery is initiated.',
        ]);
    }

    /** Authorization is delegated to the existing invoice-payment history policy. */
    private function authorizedInvoice(User $actor, string $companyId, string $invoiceId): array
    {
        if (! Str::isUuid($invoiceId)) {
            throw new InvalidArgumentException('Invalid invoice reference.');
        }
        $balance = $this->payments->history($actor, $companyId, $invoiceId);
        $invoice = DB::table('venue_invoices')->where('company_id', $companyId)
            ->where('id', $invoiceId)->first();
        if (! $invoice) {
            throw new AuthorizationException('Issued invoice is not available.');
        }
        return [$invoice, $balance];
    }

    private function render(array $lines): string
    {
        $ops = "BT\n/F1 11 Tf\n";
        $y = 750;
        foreach (array_slice($lines, 0, 43) as $line) {
            // Helvetica core font is ASCII-only; escape PDF operators/parentheses.
            $line = preg_replace('/[^\x20-\x7E]/', '?', (string) $line);
            $line = substr($line, 0, 98);
            $line = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $ops .= "1 0 0 1 42 $y Tm\n($line) Tj\n";
            $y -= 16;
        }
        if (count($lines) > 43) {
            throw new InvalidArgumentException('Document exceeds one-page supported line limit.');
        }
        $ops .= "ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            "<< /Length ".strlen($ops)." >>\nstream\n".$ops."endstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n"
            .$xref."\n%%EOF\n";
    }
}
