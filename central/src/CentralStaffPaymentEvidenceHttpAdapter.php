<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL, default-disabled bridge for future HAFZATECH billing staff pages.
 * It does not register routes, read PHP cookies, return session/CSRF tokens,
 * accept bank-confirmation facts, or activate an installation.
 *
 * A trusted operator-only HTTP front controller MUST supply the exact HTTPS,
 * same-origin server values and host-only Secure HttpOnly session cookie.
 * Both actions require POST, live MFA permission and bound CSRF, including the
 * read action, so customer claims cannot be fetched through a cross-site GET.
 */
final class CentralStaffPaymentEvidenceHttpAdapter
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffProtectedHttpBoundary $boundary,
        private readonly CentralStaffPaymentEvidenceQueue $queue,
        private readonly CentralStaffPaymentEvidenceReviewService $reviews,
        private readonly bool $enabled = false
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function pending(array $server, string $sessionCookie,
        string $csrfHeader, string $customerId, int $offset=0, int $limit=25): array
    {
        $this->authorize($server,'/staff/billing/payment-evidence/pending',
            $sessionCookie,$csrfHeader,$customerId);
        return $this->queue->listPending($sessionCookie,$customerId,$offset,$limit);
    }

    /** @return array{evidence_id:string,decision:string,reviewed_by:string} */
    public function decide(array $server, string $sessionCookie,
        string $csrfHeader, string $customerId, string $evidenceId,
        string $decision, string $reason): array
    {
        $this->authorize($server,'/staff/billing/payment-evidence/decide',
            $sessionCookie,$csrfHeader,$customerId);
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $evidenceId)!==1) {
            throw new RegistrationError('invalid_payment_evidence_review',422);
        }
        // Prevent a globally scoped Billing Officer using an authenticated
        // customer A page to alter customer B evidence through a forged row ID.
        $q=$this->db->prepare(
            'SELECT i.customer_id
             FROM central_vendor_payment_evidence e
             JOIN central_vendor_invoices i ON i.id=e.invoice_id
             WHERE e.id=:evidence'
        );
        $q->execute(['evidence'=>$evidenceId]);
        if ($q->fetchColumn()!==$customerId) {
            throw new RegistrationError('payment_evidence_not_found');
        }
        return $this->reviews->decide(
            $sessionCookie,$evidenceId,$decision,$reason);
    }

    private function authorize(array $server,string $exactPath,
        string $sessionCookie,string $csrfHeader,string $customerId): void
    {
        if (!$this->enabled) {
            throw new RegistrationError('staff_http_not_enabled');
        }
        $path=parse_url((string)($server['REQUEST_URI']??''),PHP_URL_PATH);
        if ($path!==$exactPath ||
            !$this->boundary->permitsMutation($server,$sessionCookie,$csrfHeader,
                'billing.payment.verify',$customerId)) {
            throw new RegistrationError('staff_http_denied');
        }
    }
}
