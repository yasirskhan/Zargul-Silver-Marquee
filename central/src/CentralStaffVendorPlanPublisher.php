<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use PDOException;
use Throwable;

/**
 * Central operator-only plan catalog publisher. No public route or customer
 * price control. Publish is an append-only version + immutable audit event.
 * There is no payment, license signature, plan deletion or activation here.
 */
final class CentralStaffVendorPlanPublisher
{
    private const UUID='/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D';

    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $auth,
        private readonly bool $enabled=false
    ) {}

    /** @param array<string,bool> $features
     * @return array{plan_version_id:string,version:int,term_months:int,currency:string,price_minor:string}
     */
    public function publish(string $bearer,string $requestId,string $planCode,
        int $termMonths,string $currency,string $priceMinor,int $maxHalls,
        int $maxStaff,array $features):array
    {
        if(!$this->enabled ||
            getenv('CENTRAL_VENDOR_PLAN_PUBLISH_ENABLED')!=='true') {
            throw new RegistrationError('vendor_plan_publish_not_enabled',503);
        }
        if(preg_match(self::UUID,$requestId)!==1 ||
            preg_match('/^[a-z][a-z0-9_]{2,63}$/D',$planCode)!==1 ||
            !in_array($termMonths,[6,12],true) ||
            preg_match('/^[A-Z]{3}$/D',$currency)!==1 ||
            preg_match('/^[1-9][0-9]{0,15}$/D',$priceMinor)!==1 ||
            $maxHalls<1 || $maxHalls>100000 ||
            $maxStaff<1 || $maxStaff>100000 ||
            count($features)<1 || count($features)>32) {
            throw new RegistrationError('invalid_vendor_plan_request',422);
        }
        foreach($features as $name=>$enabled){
            if(!is_string($name) ||
               preg_match('/^[a-z][a-z0-9_]{1,48}$/D',$name)!==1 ||
               !is_bool($enabled)) {
                throw new RegistrationError('invalid_vendor_plan_request',422);
            }
        }
        ksort($features,SORT_STRING);
        $benefits=['max_halls'=>$maxHalls,'max_staff'=>$maxStaff,
            'features'=>$features];
        $snapshot=json_encode($benefits,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $digest=hash('sha256',json_encode([
            $planCode,$termMonths,$currency,$priceMinor,$benefits
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));

        $actor=$this->sessions->staffFor($bearer);
        if(!is_string($actor) || !$this->auth->permits(
            $bearer,'plan.version.create',null)) {
            throw new RegistrationError('vendor_plan_administrator_required');
        }
        $this->db->beginTransaction();
        try {
            // Vendor plan publishes are low-frequency. A single advisory lock
            // prevents races and duplicate plan versions, including two
            // different user-supplied request UUIDs for the same plan code.
            $this->db->query('SELECT pg_advisory_xact_lock(912,3271)');
            $q=$this->db->prepare(
                'SELECT id FROM central_staff_users
                 WHERE id=:staff AND status=\'active\' FOR SHARE'
            );
            $q->execute(['staff'=>$actor]);
            if($q->fetchColumn()!==$actor) {
                throw new RegistrationError('vendor_plan_administrator_required');
            }
            $q=$this->db->prepare(
                'SELECT id FROM central_staff_role_grants
                 WHERE staff_id=:staff AND revoked_at IS NULL
                 ORDER BY id FOR SHARE'
            );
            $q->execute(['staff'=>$actor]);
            $q->fetchAll(PDO::FETCH_ASSOC);
            if(!$this->auth->permits($bearer,'plan.version.create',null)) {
                throw new RegistrationError('vendor_plan_administrator_required');
            }
            $q=$this->db->prepare(
                'SELECT e.plan_version_id,e.issued_by_staff_id,e.request_sha256,
                        p.version,p.term_months,p.currency,p.price_minor
                 FROM central_vendor_plan_publish_events e
                 JOIN central_vendor_plan_versions p ON p.id=e.plan_version_id
                 WHERE e.request_id=:request'
            );
            $q->execute(['request'=>$requestId]);
            $old=$q->fetch(PDO::FETCH_ASSOC);
            if($old){
                if($old['issued_by_staff_id']!==$actor ||
                   !hash_equals($old['request_sha256'],$digest)) {
                    throw new RegistrationError('vendor_plan_request_conflict',409);
                }
                $this->db->commit();
                return ['plan_version_id'=>$old['plan_version_id'],
                    'version'=>(int)$old['version'],
                    'term_months'=>(int)$old['term_months'],
                    'currency'=>$old['currency'],
                    'price_minor'=>(string)$old['price_minor']];
            }

            $q=$this->db->prepare(
                'SELECT COALESCE(MAX(version),0)+1
                 FROM central_vendor_plan_versions WHERE plan_code=:code'
            );
            $q->execute(['code'=>$planCode]);
            $version=(int)$q->fetchColumn();
            if($version<1 || $version>2147483647) {
                throw new RegistrationError('vendor_plan_versions_exhausted',409);
            }
            $id=self::uuid();
            $q=$this->db->prepare(
                'INSERT INTO central_vendor_plan_versions
                 (id,plan_code,version,term_months,currency,price_minor,entitlements_snapshot)
                 VALUES(:id,:code,:version,:term,:currency,:price,:benefits)'
            );
            $q->execute(['id'=>$id,'code'=>$planCode,'version'=>$version,
                'term'=>$termMonths,'currency'=>$currency,'price'=>$priceMinor,
                'benefits'=>$snapshot]);
            $q=$this->db->prepare(
                'INSERT INTO central_vendor_plan_publish_events
                 (request_id,plan_version_id,issued_by_staff_id,request_sha256)
                 VALUES(:request,:plan,:actor,:digest)'
            );
            $q->execute(['request'=>$requestId,'plan'=>$id,
                'actor'=>$actor,'digest'=>$digest]);
            $this->db->commit();
            return ['plan_version_id'=>$id,'version'=>$version,
                'term_months'=>$termMonths,'currency'=>$currency,
                'price_minor'=>$priceMinor];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            if($e instanceof PDOException && $e->getCode()==='23505') {
                throw new RegistrationError('vendor_plan_request_conflict',409);
            }
            throw $e;
        }
    }

    private static function uuid():string
    {
        $b=random_bytes(16);
        $b[6]=chr((ord($b[6])&15)|64);
        $b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.
            substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
