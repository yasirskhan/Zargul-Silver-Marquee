<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read-only INTERNAL manifest for an isolated vendor KMS signing worker.
 * Produces the exact claims of an independently paid prepared revision.
 * Never signs, exports keys, changes revision state or exposes HTTP routes.
 * Recheck eligibility under transaction lock at finalization: a manifest is
 * not a grant and can become stale after a settlement reversal or role change.
 */
final class CentralStaffSignedGrantManifest
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization,
        private readonly PaidInvoiceEligibility $payment
    ) {}

    /** @return array<string,mixed> */
    public function forRevision(string $vendorSessionBearer, string $revisionId): array
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $revisionId) !== 1) {
            throw new RegistrationError('invalid_license_request', 422);
        }
        $actor=$this->sessions->staffFor($vendorSessionBearer);
        if ($actor===null) throw new RegistrationError('staff_session_required');
        $q=$this->db->prepare(
            "SELECT r.id,r.license_id,r.revision,r.invoice_id,r.state,
                    r.term_months,r.begins_at,r.expires_at,r.plan_snapshot,
                    r.authorized_officer_id,
                    l.customer_id,l.installation_id,l.approved_domain,l.current_revision,
                    i.status AS invoice_status,p.plan_code
             FROM central_paid_license_revisions r
             JOIN central_licenses l ON l.id=r.license_id
             JOIN central_vendor_invoices i ON i.id=r.invoice_id AND i.customer_id=l.customer_id
             JOIN central_vendor_plan_versions p ON p.id=i.plan_version_id
             JOIN registered_installations inst ON inst.id=l.installation_id
                 AND inst.customer_id=l.customer_id AND inst.status='active'
             JOIN vendor_customers c ON c.id=l.customer_id AND c.status='active'
             WHERE r.id=:revision"
        );
        $q->execute(['revision'=>$revisionId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RegistrationError('signed_grant_not_eligible');
        if ($row['authorized_officer_id']!==$actor ||
            !$this->authorization->permits(
                $vendorSessionBearer,'license.issue_paid',$row['customer_id'])) {
            throw new RegistrationError('license_officer_required');
        }
        if ($row['state']!=='awaiting_isolated_signer' ||
            (int)$row['revision']!==(int)$row['current_revision'] ||
            $row['invoice_status']!=='issued' ||
            !$this->payment->isEligible($row['invoice_id'],$actor)) {
            throw new RegistrationError('signed_grant_not_eligible');
        }
        $plan=json_decode($row['plan_snapshot'],true,16,JSON_THROW_ON_ERROR);
        if (!is_array($plan) ||
            !is_int($plan['max_halls']??null) ||
            $plan['max_halls']<1 || $plan['max_halls']>10000 ||
            !is_int($plan['max_staff']??null) ||
            $plan['max_staff']<1 || $plan['max_staff']>100000 ||
            !is_array($plan['features']??null) ||
            array_is_list($plan['features']) || count($plan['features'])>150) {
            throw new RegistrationError('invalid_grant_claim');
        }
        foreach ($plan['features'] as $name=>$enabled) {
            if (!is_string($name) ||
                preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D',$name)!==1 ||
                !is_bool($enabled)) {
                throw new RegistrationError('invalid_plan_feature_snapshot');
            }
        }
        $utc=new DateTimeZone('UTC');
        $credit=CentralStaffDesignerCreditService::currentForLicense(
            $this->db,(string)$row['license_id']);
        $claims=[
            'api_version'=>'v1',
            'issuer'=>'HAFZATECH',
            'product'=>'VenueDesk',
            'license_id'=>$row['license_id'],
            'customer_id'=>$row['customer_id'],
            'installation_id'=>$row['installation_id'],
            'revision'=>(int)$row['revision'],
            'term_months'=>(int)$row['term_months'],
            'edition'=>$row['plan_code'],
            'approved_domains'=>[$row['approved_domain']],
            'not_before'=>(new DateTimeImmutable($row['begins_at']))->setTimezone($utc)->format('Y-m-d\\TH:i:s\\Z'),
            'expires_at'=>(new DateTimeImmutable($row['expires_at']))->setTimezone($utc)->format('Y-m-d\\TH:i:s\\Z'),
            'limits'=>['max_halls'=>$plan['max_halls'],'max_staff'=>$plan['max_staff']],
            'features'=>$plan['features'],
        ];
        if ($credit !== null) {
            $claims['designer_credit']=$credit;
        }

        return $claims;
    }
}
