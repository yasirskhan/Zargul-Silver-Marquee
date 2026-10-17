<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Data-side second gate for an ALREADY centrally authenticated staff session.
 * The caller's MFA proof must come from the future trusted central auth layer;
 * untrusted requests must never be allowed to set this boolean themselves.
 * No central login or staff API route is enabled in this batch.
 */
final class CentralStaffAccess
{
    public function __construct(private readonly PDO $db) {}

    public function permits(string $staffId, string $capability,
        ?string $customerId, bool $trustedSessionMfaVerified): bool
    {
        if (!$trustedSessionMfaVerified) return false;
        $stmt = $this->db->prepare(
            "SELECT g.role, g.customer_scope_id
             FROM central_staff_users s
             JOIN central_staff_role_grants g ON g.staff_id = s.id AND g.revoked_at IS NULL
             WHERE s.id = :staff AND s.status = 'active' AND s.mfa_enrolled_at IS NOT NULL"
        );
        $stmt->execute(['staff' => $staffId]);
        $grants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $roles = array_unique(array_column($grants, 'role'));
        if (in_array('billing_officer', $roles, true) &&
            in_array('license_officer', $roles, true)) {
            // A mistaken direct database grant must never bypass separation.
            return false;
        }
        foreach ($grants as $grant) {
            if ($grant['customer_scope_id'] !== null &&
                $grant['customer_scope_id'] !== $customerId) continue;
            if (StaffRolePermissions::roleAllows($grant['role'], $capability)) return true;
        }

        return false;
    }
}
