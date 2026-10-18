<?php

namespace App\Services;

use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TenantAccess
{
    private function membership(User $user, string $companyId): ?CompanyMembership
    {
        $member=$this->restrictedRecordMembership($user,$companyId);
        return $member !== null &&
            app(CentralVenueOperationsAccessGate::class)->allowsCompany($companyId)
                ? $member : null;
    }

    /**
     * Narrow data-rights path: tenant historical audit/export and a previously
     * approved, already-received payment refund are lawful obligations even
     * after a paid license's operations grace ends. NEVER use this for new
     * bookings, new deposits, pricing, discount approvals or CMS edits.
     */
    private function restrictedRecordMembership(User $user,string $companyId): ?CompanyMembership
    {
        if (config('central_control.activation_enabled') === true &&
            ! DB::table('central_installation_activation')
                ->where('singleton_id',1)
                ->where('company_id',$companyId)
                ->where('status','registered_pending_license')
                ->exists()) {
            return null;
        }
        // A cached authenticated User can outlive an administrator's
        // account revocation or email-verification reset in another process.
        // Read current persisted account state for every hall/tenant policy.
        if (! $user->is_active || ! $user->hasVerifiedEmail()
            || ! DB::table('users')->where('id', $user->getKey())
                ->where('is_active', true)->whereNotNull('email_verified_at')->exists()) {
            return null;
        }

        $membership = CompanyMembership::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->first();

        return $membership;
    }

    public function canViewHall(User $user, Hall $hall): bool
    {
        if (! $hall->is_active || ! ($membership = $this->membership($user, $hall->company_id))) {
            return false;
        }

        if (in_array($membership->role, ['owner', 'admin'], true)) {
            return true;
        }

        return $membership->hallAssignments()->where('hall_id', $hall->getKey())->exists();
    }

    public function canEditWebsite(User $user, string $companyId): bool
    {
        $membership = $this->membership($user, $companyId);

        return $membership && (
            in_array($membership->role, ['owner', 'admin'], true)
            || $membership->can_edit_site
        );
    }

    public function canPublishWebsite(User $user, string $companyId): bool
    {
        $membership = $this->membership($user, $companyId);

        return $membership && (
            $membership->role === 'owner'
            || $membership->can_publish_site
        );
    }

    /** Sensitive hall pricing and hours are configured by active owner/admin only. */
    public function canConfigureHall(User $user, Hall $hall): bool
    {
        $membership = $this->membership($user, $hall->company_id);

        return $hall->is_active
            && $membership !== null
            && in_array($membership->role, ['owner', 'admin'], true);
    }

    /** Until hall-linked customer permissions are approved, limit PII to owners/admins. */
    public function canManageCustomerProfiles(User $user, string $companyId): bool
    {
        $membership = $this->membership($user, $companyId);

        return $membership !== null && in_array($membership->role, ['owner', 'admin'], true);
    }

    /** Historical audit/read-only export remains owner/admin-scoped post-expiry. */
    public function canReadAuditHistory(User $user, string $companyId): bool
    {
        $membership = $this->restrictedRecordMembership($user, $companyId);

        return $membership !== null && in_array($membership->role, ['owner', 'admin'], true);
    }

    /** Read-only existing invoice/payment history is a customer data right,
     * never authority to accept new deposits or alter prior ledger entries.
     */
    public function canReadExistingInvoiceHistory(User $user, Hall $hall): bool
    {
        $membership=$this->restrictedRecordMembership($user,$hall->company_id);
        return $hall->is_active && $membership !== null &&
            in_array($membership->role,['owner','admin'],true);
    }

    /** A narrowly approved refund of an EXISTING payment is not a new sale.
     * VenueInvoiceRefundService still enforces original payment/approval,
     * dual control, ledger reversal and all three posting release gates.
     */
    public function canCorrectExistingApprovedRefund(User $user, Hall $hall): bool
    {
        $membership=$this->restrictedRecordMembership($user,$hall->company_id);
        return $hall->is_active && $membership !== null &&
            in_array($membership->role,['owner','admin'],true);
    }

    /** Catalog edits are hall-specific and require active assigned manager or owner/admin. */
    public function canManageHallInventory(User $user, Hall $hall): bool
    {
        $membership = $this->membership($user, $hall->company_id);

        if (! $hall->is_active || $membership === null) {
            return false;
        }
        if (in_array($membership->role, ['owner', 'admin'], true)) {
            return true;
        }

        return $membership->role === 'manager'
            && $membership->hallAssignments()->where('hall_id', $hall->getKey())->exists();
    }

    public function canManageBookings(User $user, Hall $hall): bool
    {
        $membership = $this->membership($user, $hall->company_id);

        return $membership
            && in_array($membership->role, ['owner', 'admin', 'manager', 'reception'], true)
            && $this->canViewHall($user, $hall);
    }
}
