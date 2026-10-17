<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PREPARES paid license revisions, but cannot sign, deliver or activate them.
 * Requires a trusted centrally MFA-authenticated session from the future
 * secure staff interface; an actor ID supplied by a client is never trusted.
 * Sensitive signing must re-authorize payment against current settlement.
 */
final class PaidLicensePreparation
{
    public function __construct(
        private readonly PDO $db,
        private readonly PaidInvoiceEligibility $eligibility,
        private readonly CentralStaffAccess $access
    ) {}

    /** @return array{license_id:string,revision_id:string,revision:int,from:string,expiry:string,state:string} */
    public function prepare(string $invoiceId, string $installationId,
        string $officerId, bool $trustedMfaSessionVerified, DateTimeImmutable $now
    ): array {
        if (!$trustedMfaSessionVerified) throw new RegistrationError('mfa_required');
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare(
                "SELECT i.id,i.customer_id,i.plan_version_id,i.status,i.currency,
                        p.term_months,p.plan_code,p.entitlements_snapshot
                 FROM central_vendor_invoices i
                 JOIN central_vendor_plan_versions p ON p.id=i.plan_version_id
                 WHERE i.id=:invoice FOR UPDATE OF i"
            );
            $stmt->execute(['invoice'=>$invoiceId]);
            $invoice=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$invoice || $invoice['status']!=='issued')
                throw new RegistrationError('invoice_not_issued');

            $stmt=$this->db->prepare(
                "SELECT id,customer_id,approved_domain,status FROM registered_installations
                 WHERE id=:installation AND customer_id=:customer FOR UPDATE"
            );
            $stmt->execute(['installation'=>$installationId,'customer'=>$invoice['customer_id']]);
            $installation=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$installation || $installation['status']!=='active')
                throw new RegistrationError('installation_not_eligible');
            if(!$this->access->permits($officerId,'license.issue_paid',$invoice['customer_id'],true) ||
               !$this->eligibility->isEligible($invoiceId,$officerId))
                throw new RegistrationError('independent_payment_or_officer_required');

            $currentStmt=$this->db->prepare(
                "SELECT id,current_revision FROM central_licenses WHERE installation_id=:id FOR UPDATE"
            );
            $currentStmt->execute(['id'=>$installationId]);
            $license=$currentStmt->fetch(PDO::FETCH_ASSOC);
            // Locked invoice + unique invoice reference make retries exact and harmless.
            $old=$this->db->prepare(
                "SELECT r.id,r.license_id,r.revision,r.begins_at,r.expires_at,r.state,
                        r.authorized_officer_id
                 FROM central_paid_license_revisions r WHERE r.invoice_id=:invoice"
            );
            $old->execute(['invoice'=>$invoiceId]);
            $existing=$old->fetch(PDO::FETCH_ASSOC);
            if($existing){
                if (!$license || $existing['license_id']!==$license['id'] ||
                    $existing['authorized_officer_id']!==$officerId)
                    throw new RegistrationError('invoice_already_bound');
                $this->db->commit();
                return self::response($existing);
            }

            if(!$license){
                $licenseId=self::uuid();
                $stmt=$this->db->prepare(
                    "INSERT INTO central_licenses(id,customer_id,installation_id,approved_domain)
                     VALUES(:license,:customer,:installation,:domain)"
                );
                $stmt->execute(['license'=>$licenseId,'customer'=>$invoice['customer_id'],
                    'installation'=>$installationId,'domain'=>$installation['approved_domain']]);
                $revision=1;
                $paidExpiry=null;
            }else{
                $licenseId=$license['id'];
                $revision=(int)$license['current_revision']+1;
                $stmt=$this->db->prepare(
                    "SELECT expires_at,state FROM central_paid_license_revisions
                     WHERE license_id=:license ORDER BY revision DESC LIMIT 1"
                );
                $stmt->execute(['license'=>$licenseId]);
                $last=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$last || $last['state']!=='signed')
                    throw new RegistrationError('previous_revision_unsigned');
                $paidExpiry=new DateTimeImmutable($last['expires_at']);
            }

            $period=LicenseTermCalendar::paidTerm((int)$invoice['term_months'],$now,$paidExpiry);
            $snapshot=json_decode($invoice['entitlements_snapshot'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($snapshot) || array_is_list($snapshot) ||
               !isset($snapshot['max_halls'],$snapshot['max_staff']) ||
               !is_int($snapshot['max_halls']) || $snapshot['max_halls']<1 ||
               !is_int($snapshot['max_staff']) || $snapshot['max_staff']<1)
                throw new RegistrationError('invalid_plan_limits');

            $revisionId=self::uuid();
            $stmt=$this->db->prepare(
                "INSERT INTO central_paid_license_revisions
                 (id,license_id,invoice_id,revision,term_months,begins_at,expires_at,
                  plan_snapshot,authorized_officer_id)
                 VALUES(:id,:license,:invoice,:rev,:term,:starts,:expiry,:snapshot,:officer)"
            );
            $stmt->execute(['id'=>$revisionId,'license'=>$licenseId,'invoice'=>$invoiceId,
                'rev'=>$revision,'term'=>(int)$invoice['term_months'],
                'starts'=>$period['effective_from'],'expiry'=>$period['expires_at'],
                'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'officer'=>$officerId]);
            $stmt=$this->db->prepare(
                "UPDATE central_licenses SET current_revision=:rev WHERE id=:id"
            );
            $stmt->execute(['rev'=>$revision,'id'=>$licenseId]);
            $stmt=$this->db->prepare(
                "INSERT INTO central_license_issuance_audit
                  (customer_id,license_id,revision_id,invoice_id,officer_id,action)
                 VALUES(:customer,:license,:revision,:invoice,:officer,
                 'paid_license_revision.prepared')"
            );
            $stmt->execute(['customer'=>$invoice['customer_id'],'license'=>$licenseId,
                'revision'=>$revisionId,'invoice'=>$invoiceId,'officer'=>$officerId]);
            $this->db->commit();
            return ['license_id'=>$licenseId,'revision_id'=>$revisionId,
                'revision'=>$revision,'from'=>$period['effective_from'],
                'expiry'=>$period['expires_at'],'state'=>'awaiting_isolated_signer'];
        }catch(Throwable $error){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $row */
    private static function response(array $row): array {
        return [
            'license_id'=>$row['license_id'],'revision_id'=>$row['id'],
            'revision'=>(int)$row['revision'],
            'from'=> (new DateTimeImmutable($row['begins_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'expiry'=> (new DateTimeImmutable($row['expires_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'state'=>$row['state'],
        ];
    }

    private static function uuid(): string {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
