<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One self-hosted VenueDesk installation belongs to one operating company.
 * Do not expose this service directly as an unauthenticated installer API.
 * Initial binding needs an authenticated, verified active owner, and cannot
 * be transferred by normal staff or renewal. Exceptional move is a separately
 * approved signed HAFZATECH recovery workflow, not implemented here.
 */
final class CentralInstallationCompanyScope
{
    /** Permit only a verified ACTIVE owner to begin a central enrollment.
     * Rechecked again inside bind() after the trusted vendor round-trip.
     * No external HTTPS request is allowed before this local preflight.
     */
    public function assertCanEnroll(User $actor, string $companyId): void
    {
        if (config('central_control.activation_enabled') !== true) {
            throw new AuthorizationException('Central enrollment is not enabled.');
        }
        $this->assertActor($actor,$companyId,['owner']);
        $existing=DB::table('central_installation_activation')
            ->where('singleton_id',1)->first();
        if ($existing && $existing->company_id !== null &&
            $existing->company_id !== $companyId) {
            throw new AuthorizationException('Installation is already bound to another company.');
        }
    }

    /** @return array{company_id:string,installation_id:string} */
    public function bind(User $actor, string $companyId): array
    {
        if (config('central_control.activation_enabled') !== true) {
            throw new AuthorizationException('Central enrollment is not enabled.');
        }
        $this->assertActor($actor,$companyId,['owner']);
        return DB::transaction(function () use ($companyId): array {
            DB::select('SELECT pg_advisory_xact_lock(708116, 1115)');
            $registration=DB::table('central_installation_activation')
                ->where('singleton_id',1)->lockForUpdate()->first();
            if (!$registration || $registration->status !== 'registered_pending_license') {
                throw new InvalidArgumentException('Trusted enrollment must complete before company binding.');
            }
            if ($registration->company_id !== null &&
                $registration->company_id !== $companyId) {
                throw new AuthorizationException('Installation is already bound to another company.');
            }
            if ($registration->company_id === null) {
                DB::table('central_installation_activation')->where('singleton_id',1)
                    ->update(['company_id'=>$companyId,'updated_at'=>now()]);
            }
            return ['company_id'=>$companyId,'installation_id'=>$registration->installation_id];
        });
    }

    /** Owner/admin view is scoped to the only locally enrolled business. */
    public function assertCanRead(User $actor,string $companyId): void
    {
        $this->assertActor($actor,$companyId,['owner','admin']);
        $binding=DB::table('central_installation_activation')->where('singleton_id',1)->first();
        if (!$binding || $binding->company_id === null ||
            $binding->company_id !== $companyId ||
            $binding->status !== 'registered_pending_license') {
            throw new AuthorizationException('No installation license is bound to this operating company.');
        }
    }

    /** @param array<int,string> $roles */
    private function assertActor(User $actor,string $companyId,array $roles): void
    {
        if (! DB::table('users')->where('id',$actor->getKey())
            ->where('is_active',true)->whereNotNull('email_verified_at')->exists() ||
            ! DB::table('company_memberships as m')
                ->join('companies as c','c.id','=','m.company_id')
                ->where('m.company_id',$companyId)
                ->where('m.user_id',$actor->getKey())
                ->where('m.is_active',true)->where('c.is_active',true)
                ->whereIn('m.role',$roles)->exists()) {
            throw new AuthorizationException('An active verified company owner is required.');
        }
    }
}
