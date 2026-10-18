<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use DateTimeImmutable;
use PDO;

/**
 * INTERNAL staff-session-to-paid-license boundary. A caller cannot supply a
 * staff UUID or assert that MFA passed; current actor and scoped permission
 * are derived from the server-issued vendor session on every request.
 *
 * Does not sign, deliver or activate any grant. HTTP, signer and payment
 * release gates remain separately disabled pending operator approval.
 */
final class CentralStaffPaidLicensePreparation
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization,
        private readonly PaidLicensePreparation $preparation
    ) {}

    /** @return array{license_id:string,revision_id:string,revision:int,from:string,expiry:string,state:string} */
    public function prepare(string $vendorSessionBearer, string $invoiceId,
        string $installationId, DateTimeImmutable $now): array
    {
        if (!self::uuid($invoiceId) || !self::uuid($installationId)) {
            throw new RegistrationError('invalid_license_request', 422);
        }
        $actor = $this->sessions->staffFor($vendorSessionBearer);
        if ($actor === null) {
            throw new RegistrationError('staff_session_required');
        }

        // Resolve customer scope from central immutable invoice ownership,
        // NEVER a customer ID or permission assertion supplied by the caller.
        $q = $this->db->prepare(
            'SELECT customer_id FROM central_vendor_invoices WHERE id=:invoice'
        );
        $q->execute(['invoice' => $invoiceId]);
        $customer = $q->fetchColumn();
        if (!is_string($customer) ||
            !$this->authorization->permits($vendorSessionBearer,
                'license.issue_paid', $customer)) {
            throw new RegistrationError('license_officer_required');
        }

        // The underlying transaction rechecks active independent Billing
        // Officer settlement, invoice, installation, current role and payment
        // eligibility while locked. A revoked staff grant cannot bypass it.
        return $this->preparation->prepare(
            $invoiceId, $installationId, $actor, true, $now
        );
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
    }
}
