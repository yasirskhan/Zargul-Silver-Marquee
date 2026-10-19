<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Owner/admin-initiated contact request, NOT a paid subscription, invoice,
 * KMS signature, renewal approval or central operator API. Reuses the
 * encrypted local support outbox and existing tenant authorization.
 *
 * One deterministic, scoped UUID per installation, current signed revision
 * and requested term makes repeated browser clicks idempotent across sessions.
 */
final class CentralOwnerLicenseReviewRequest
{
    public function __construct(
        private readonly CentralInstallationCompanyScope $scope,
        private readonly CentralLicensingOverviewService $overview,
        private readonly CentralSupportOutboxService $support
    ) {}

    /** @return array{ticket_id:string,state:string} */
    public function queue(User $actor,string $companyId,int $termMonths):array
    {
        if (config('central_control.activation_enabled') !== true ||
            config('central_control.support_enabled') !== true) {
            throw new AuthorizationException('Central license review is not enabled.');
        }
        $this->scope->assertCanRead($actor,$companyId);
        if (!in_array($termMonths,[6,12],true)) {
            throw new InvalidArgumentException('Only six- and twelve-month terms are supported.');
        }
        $binding=DB::table('central_installation_activation')
            ->where('singleton_id',1)->first();
        if (!$binding || $binding->company_id !== $companyId) {
            throw new AuthorizationException('Installation company binding is required.');
        }

        // Only the existing authenticated LOCAL signature-verification
        // service can describe current license/expiry. Never allow browser
        // supplied license IDs, invoices, dates, prices or "payment complete".
        $snapshot=$this->overview->snapshotForCompany(
            $actor,$companyId,now()->toDateTimeImmutable());
        if (in_array($snapshot['state'],['feature_disabled','enrollment_required',
            'invalid_signed_license'],true)) {
            throw new InvalidArgumentException('License identity requires review.');
        }
        if ($snapshot['renewal_revision'] !== null) {
            throw new InvalidArgumentException('A signed renewal is already scheduled.');
        }

        $licenseId=$snapshot['license_id'] ?? 'not_yet_issued';
        $revision=$snapshot['revision'] ?? 0;
        $subject='VenueDesk license review - '.$termMonths.' months';
        $body='Please review an independently payable HAFZATECH VenueDesk '.
            $termMonths.'-month license or renewal request.'."\n".
            'Local signed license ID: '.$licenseId."\n".
            'Local signed revision: '.$revision."\n".
            'Signed expiry (UTC): '.($snapshot['expires_at'] ?? 'not issued')."\n".
            'This request does not confirm payment, change entitlements, or authorize signing.';
        $requestId=Uuid::uuid5(Uuid::NAMESPACE_URL,implode('|',[
            'HAFZATECH-VENUEDESK-LICENSE-REVIEW-V1',
            $binding->installation_id,$companyId,$licenseId,
            (string)$revision,(string)$termMonths,
        ]))->toString();

        // Existing service rechecks current owner/admin role INSIDE its
        // protected transaction. The queued content is encrypted at rest.
        return $this->support->queue($actor,$companyId,$requestId,$subject,$body);
    }
}
