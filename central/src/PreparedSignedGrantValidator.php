<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Central read-only validation of an EXTERNALLY signed paid grant, prior to
 * eventual encrypted storage. Does not sign, activate, send or commit a grant.
 * The separate worker supplies a vendor-signed envelope; approved public keys
 * are provisioned out of band, never fetched from a client or network request.
 * The caller must already hold a trusted central MFA-backed staff session.
 */
final class PreparedSignedGrantValidator
{
    /** @param array<string,string> $trustedPublicKeys */
    public function __construct(
        private readonly PDO $db,
        private readonly PaidInvoiceEligibility $payment,
        private readonly CentralStaffAccess $access,
        private readonly array $trustedPublicKeys
    ) {}

    /** @param array<string,mixed> $envelope
     * @return array{revision_id:string,license_id:string,revision:int,customer_id:string}
     */
    public function validate(string $revisionId, string $officerId, bool $trustedMfa,
        array $envelope): array
    {
        if (!$trustedMfa) throw new RegistrationError('mfa_required');
        $stmt=$this->db->prepare(
            "SELECT r.id,r.license_id,r.revision,r.invoice_id,r.state,
                r.term_months,r.begins_at,r.expires_at,r.plan_snapshot,
                r.authorized_officer_id,
                l.customer_id,l.installation_id,l.approved_domain,l.current_revision,
                i.status AS invoice_status,
                p.plan_code
             FROM central_paid_license_revisions r
             JOIN central_licenses l ON l.id=r.license_id
             JOIN central_vendor_invoices i ON i.id=r.invoice_id AND i.customer_id=l.customer_id
             JOIN central_vendor_plan_versions p ON p.id=i.plan_version_id
             JOIN registered_installations inst ON inst.id=l.installation_id
                AND inst.customer_id=l.customer_id AND inst.status='active'
             JOIN vendor_customers c ON c.id=l.customer_id AND c.status='active'
             WHERE r.id=:revision"
        );
        $stmt->execute(['revision'=>$revisionId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['current_revision'] !== (int)$row['revision'] ||
            $row['state']!=='awaiting_isolated_signer' ||
            $row['invoice_status']!=='issued' ||
            $row['authorized_officer_id']!==$officerId ||
            !$this->access->permits($officerId,'license.issue_paid',$row['customer_id'],true) ||
            !$this->payment->isEligible($row['invoice_id'],$officerId)) {
            throw new RegistrationError('signed_grant_not_eligible');
        }
        $fields=array_keys($envelope);sort($fields);
        if ($fields!==['key_id','payload','signature'] ||
            !is_string($envelope['key_id']) ||
            !isset($this->trustedPublicKeys[$envelope['key_id']])) {
            throw new RegistrationError('untrusted_grant_signer');
        }
        $public=self::decode($this->trustedPublicKeys[$envelope['key_id']],32);
        $raw=self::decode($envelope['payload'],null);
        $sig=self::decode($envelope['signature'],64);
        if(strlen($raw)>16384 || !sodium_crypto_sign_verify_detached(
            $sig,'HAFZATECH-LICENSE-GRANT-V1'."\n".$raw,$public)){
            throw new RegistrationError('invalid_grant_signature');
        }
        $claim=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        $snapshot=json_decode($row['plan_snapshot'],true,16,JSON_THROW_ON_ERROR);
        if (!is_array($claim)||array_is_list($claim)||!is_array($snapshot) ||
            !is_int($snapshot['max_halls']??null) ||
            $snapshot['max_halls'] < 1 || $snapshot['max_halls'] > 10000 ||
            !is_int($snapshot['max_staff']??null) ||
            $snapshot['max_staff'] < 1 || $snapshot['max_staff'] > 100000 ||
            !is_array($snapshot['features']??null) ||
            array_is_list($snapshot['features']) ||
            count($snapshot['features']) > 150) {
            throw new RegistrationError('invalid_grant_claim');
        }
        foreach ($snapshot['features'] as $name=>$enabled) {
            if (!is_string($name) ||
                !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D',$name) ||
                !is_bool($enabled)) {
                throw new RegistrationError('invalid_plan_feature_snapshot');
            }
        }
        $keys=array_keys($claim);sort($keys);
        if($keys!==['api_version','approved_domains','customer_id','edition',
            'expires_at','features','installation_id','issuer','license_id',
            'limits','not_before','product','revision','term_months']) {
            throw new RegistrationError('invalid_grant_claim');
        }
        $start=(new DateTimeImmutable($row['begins_at']))->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        $end=(new DateTimeImmutable($row['expires_at']))->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        if($claim['api_version']!=='v1'||$claim['issuer']!=='HAFZATECH'||
            $claim['product']!=='VenueDesk'||$claim['license_id']!==$row['license_id']||
            $claim['customer_id']!==$row['customer_id']||
            $claim['installation_id']!==$row['installation_id']||
            $claim['revision']!==(int)$row['revision']||
            $claim['term_months']!==(int)$row['term_months']||
            $claim['edition']!==$row['plan_code']||
            $claim['approved_domains']!==[$row['approved_domain']]||
            $claim['not_before']!==$start||$claim['expires_at']!==$end||
            $claim['limits']!==[
                'max_halls'=>$snapshot['max_halls']??null,
                'max_staff'=>$snapshot['max_staff']??null,
            ]||
            $claim['features']!==($snapshot['features']??null)) {
            throw new RegistrationError('grant_does_not_match_paid_revision');
        }
        return ['revision_id'=>$row['id'],'license_id'=>$row['license_id'],
            'revision'=>(int)$row['revision'],'customer_id'=>$row['customer_id']];
    }

    private static function decode(mixed $value,?int $len): string
    {
        if(!is_string($value)||!preg_match('/^[A-Za-z0-9_-]+$/D',$value)) {
            throw new RegistrationError('invalid_grant_encoding',422);
        }
        $raw=base64_decode(strtr($value,'-_','+/'),true);
        if($raw===false||($len!==null&&strlen($raw)!==$len)||
            rtrim(strtr(base64_encode($raw),'+/','-_'),'=')!==$value){
            throw new RegistrationError('invalid_grant_encoding',422);
        }
        return $raw;
    }
}
