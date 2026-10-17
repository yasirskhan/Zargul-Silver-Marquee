<?php
declare(strict_types=1);

namespace Hafzatech\Central;

/**
 * Immutable vendor role capability policy. Effective grants may only be read
 * after a separate central MFA-authenticated staff session is established.
 * No login/session or customer endpoint is exposed in this foundational batch.
 */
final class StaffRolePermissions
{
    public const ROLE_PERMISSIONS = [
        'super_administrator' => ['staff.recovery', 'staff.roles.assign', 'security.exceptions.review', 'audit.read'],
        'staff_administrator' => ['staff.create', 'staff.disable', 'staff.password.reset', 'staff.roles.assign'],
        'billing_officer' => ['billing.invoice.issue', 'billing.payment.verify', 'billing.provider.read', 'billing.invoice.read'],
        'license_officer' => ['license.read_status', 'license.issue_paid', 'license.renew_paid'],
        'license_supervisor' => ['license.read_status', 'license.grace.approve', 'license.revoke.approve', 'license.transfer.approve'],
        'plan_administrator' => ['plan.version.create', 'plan.read'],
        'support_agent' => ['support.ticket.read', 'support.reply'],
        'support_supervisor' => ['support.ticket.read', 'support.reply', 'support.assign', 'support.knowledge.approve'],
        'auditor' => ['audit.read'],
    ];

    public static function roleAllows(string $role, string $capability): bool
    {
        return in_array($capability, self::ROLE_PERMISSIONS[$role] ?? [], true);
    }

    /** Role combinations cannot concentrate settlement verification and signing. */
    public static function compatibleWith(string $candidateRole, array $existingRoles): bool
    {
        if (! array_key_exists($candidateRole, self::ROLE_PERMISSIONS)) return false;
        $roles = array_unique([...$existingRoles, $candidateRole]);
        foreach ($roles as $role) {
            if (! array_key_exists($role, self::ROLE_PERMISSIONS)) return false;
        }

        return ! (in_array('billing_officer', $roles, true) &&
            in_array('license_officer', $roles, true));
    }

    /**
     * Policy guard applied in addition to future authenticated session checks.
     * No role assignment can elevate oneself. Sensitive grants require a
     * second authorized approver distinct from the issuer and recipient.
     */
    public static function mayGrantRole(string $actorRole, string $targetRole,
        bool $toSelf, bool $independentApproval): bool
    {
        if ($toSelf || ! self::roleAllows($actorRole, 'staff.roles.assign') ||
            ! array_key_exists($targetRole, self::ROLE_PERMISSIONS)) return false;

        if ($targetRole === 'super_administrator') {
            return $actorRole === 'super_administrator' && $independentApproval;
        }
        if (in_array($targetRole, ['billing_officer', 'license_officer', 'license_supervisor'],
            true)) return $independentApproval;

        return true;
    }

    public static function mayApprove(string $requesterId, string $approverId): bool
    {
        return $requesterId !== $approverId && $requesterId !== '' && $approverId !== '';
    }
}
