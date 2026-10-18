<?php

namespace App\Services;

use DateTimeImmutable;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * Read-only owner Licensing-page projection, not a route or access gate.
 *
 * Unreleased builds do not change any website/dashboard behavior. When the
 * commercial gate eventually becomes enabled, missing or invalid locally
 * signed grants are projected as maintenance/restricted without deleting
 * any booking, tenant or financial data.
 *
 * Caller MUST authorize an owner/admin of the installed VenueDesk business.
 * Never expose the projection as an anonymous endpoint.
 */
final class CentralLicensingOverviewService
{
    public function __construct(
        private readonly CentralSignedLicenseCache $cache,
        private readonly CentralLicenseStatusPolicy $policy,
        private readonly CentralLicenseCheckSchedule $schedule,
        private readonly CentralInstallationCompanyScope $companyScope
    ) {}

    /** @return array<string,mixed> */
    public function snapshotForCompany(User $actor,string $companyId,DateTimeImmutable $now): array
    {
        // Authorization is not derived from a tenant-selected Livewire field.
        $this->companyScope->assertCanRead($actor,$companyId);
        return $this->snapshot($now);
    }

    /** @return array<string,mixed> */
    public function snapshot(DateTimeImmutable $now): array
    {
        if (config('central_control.activation_enabled') !== true) {
            return [
                'state'=>'feature_disabled',
                'public_policy'=>'unchanged',
                'dashboard_policy'=>'unchanged',
                'expiry_warning'=>false,
                'online_check_due'=>false,
                'expires_at'=>null,
                'revision'=>null,
                'license_id'=>null,
                'edition'=>null,
                'max_halls'=>null,
                'max_staff'=>null,
            ];
        }
        $binding=DB::table('central_installation_activation')
            ->where('singleton_id',1)->first();
        if (! $binding || $binding->status !== 'registered_pending_license') {
            return $this->withoutGrant('enrollment_required',false);
        }
        $due=$this->schedule->isDue($now);
        $stored=DB::table('central_signed_license_cache')->where('singleton_id',1)->first();
        if (! $stored) {
            return $this->withoutGrant('awaiting_signed_license',$due);
        }
        try {
            $verified=$this->cache->locallyVerified($binding->customer_id,
                $binding->approved_domain,$now);
            $state=$this->policy->state($verified,$now);
        } catch (InvalidArgumentException | DecryptException | JsonException) {
            // Failed signature or altered encrypted cache never turns an
            // installation into an active paid license.
            return $this->withoutGrant('invalid_signed_license',$due);
        }
        return [
            'state'=> $state['dashboard']==='grace' ? 'expired_grace' :
                ($state['dashboard']==='restricted' ? 'expired_or_not_yet_valid' : 'licensed'),
            'public_policy'=>$state['public'],
            'dashboard_policy'=>$state['dashboard'],
            'expiry_warning'=>$state['expiry_warning'],
            'online_check_due'=>$due,
            'expires_at'=>$state['expires_at'],
            'revision'=>(int)$verified['grant']['revision'],
            'license_id'=>$verified['grant']['license_id'],
            'edition'=>$verified['grant']['edition'],
            'max_halls'=>$verified['grant']['limits']['max_halls'],
            'max_staff'=>$verified['grant']['limits']['max_staff'],
        ];
    }

    /** @return array<string,mixed> */
    private function withoutGrant(string $state,bool $due): array
    {
        return [
            'state'=>$state,
            'public_policy'=>'maintenance',
            'dashboard_policy'=>'restricted',
            'expiry_warning'=>false,
            'online_check_due'=>$due,
            'expires_at'=>null,
            'revision'=>null,
            'license_id'=>null,
            'edition'=>null,
            'max_halls'=>null,
            'max_staff'=>null,
        ];
    }
}
