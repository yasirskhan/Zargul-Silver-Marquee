<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/** Authorized, immutable designer-credit configuration for a prepared license. */
final class CentralStaffDesignerCreditService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array{license_id:string,version:int,credit:array<string,mixed>} */
    public function configure(string $bearer,string $licenseId,string $requestId,
        mixed $credit): array
    {
        if (!self::uuid($licenseId) || !self::uuid($requestId)) {
            throw new RegistrationError('invalid_designer_credit',422);
        }
        $actor=$this->sessions->staffFor($bearer);
        if ($actor===null) throw new RegistrationError('staff_session_required');
        $clean=DesignerCreditPolicy::validate($credit);
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT customer_id FROM central_licenses WHERE id=:id FOR UPDATE');
            $q->execute(['id'=>$licenseId]);
            $customer=$q->fetchColumn();
            if (!is_string($customer) ||
                !$this->authorization->permits($bearer,'license.issue_paid',$customer)) {
                throw new RegistrationError('license_officer_required');
            }
            $existing=$this->db->prepare('SELECT license_id,version,credit
                FROM central_license_designer_credits WHERE request_id=:request');
            $existing->execute(['request'=>$requestId]);
            $row=$existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $stored=json_decode((string)$row['credit'],true,16,JSON_THROW_ON_ERROR);
                if ((string)$row['license_id']!==$licenseId || $stored!==$clean) {
                    throw new RegistrationError('designer_credit_request_conflict',409);
                }
                $this->db->commit();
                return ['license_id'=>$licenseId,'version'=>(int)$row['version'],
                    'credit'=>$stored];
            }
            $version=(int)$this->db->query('SELECT COALESCE(MAX(version),0)+1 FROM central_license_designer_credits WHERE license_id='.$this->db->quote($licenseId))->fetchColumn();
            $id=self::uuidv4();
            $encoded=json_encode($clean,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            $ins=$this->db->prepare('INSERT INTO central_license_designer_credits
                (id,license_id,version,request_id,credit,configured_by)
                VALUES (:id,:license,:version,:request,CAST(:credit AS jsonb),:actor)');
            $ins->execute(['id'=>$id,'license'=>$licenseId,'version'=>$version,
                'request'=>$requestId,'credit'=>$encoded,'actor'=>$actor]);
            $audit=$this->db->prepare('INSERT INTO central_designer_credit_audit
                (credit_id,license_id,actor_id,action) VALUES (:credit,:license,:actor,:action)');
            $audit->execute(['credit'=>$id,'license'=>$licenseId,'actor'=>$actor,
                'action'=>'designer_credit.configured']);
            $this->db->commit();
            return ['license_id'=>$licenseId,'version'=>$version,'credit'=>$clean];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function history(string $bearer,string $licenseId): array
    {
        if (!self::uuid($licenseId)) throw new RegistrationError('invalid_designer_credit',422);
        $actor=$this->sessions->staffFor($bearer);
        if ($actor===null) throw new RegistrationError('staff_session_required');
        $q=$this->db->prepare('SELECT customer_id FROM central_licenses WHERE id=:id');
        $q->execute(['id'=>$licenseId]);
        $customer=$q->fetchColumn();
        if (!is_string($customer) ||
            !$this->authorization->permits($bearer,'license.read_status',$customer)) {
            throw new RegistrationError('license_officer_required');
        }
        $rows=$this->db->prepare('SELECT version,credit,created_at FROM central_license_designer_credits
            WHERE license_id=:id ORDER BY version DESC');
        $rows->execute(['id'=>$licenseId]);
        return array_map(static fn(array $r):array=>[
            'version'=>(int)$r['version'],
            'credit'=>json_decode((string)$r['credit'],true,16,JSON_THROW_ON_ERROR),
            'created_at'=>$r['created_at'],
        ],$rows->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function currentForLicense(PDO $db,string $licenseId): ?array
    {
        $q=$db->prepare('SELECT credit FROM central_license_designer_credits
            WHERE license_id=:id ORDER BY version DESC LIMIT 1');
        $q->execute(['id'=>$licenseId]);
        $value=$q->fetchColumn();
        return is_string($value) ? json_decode($value,true,16,JSON_THROW_ON_ERROR) : null;
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$value)===1;
    }

    private static function uuidv4(): string
    {
        $b=random_bytes(16); $b[6]=chr((ord($b[6])&0x0f)|0x40);
        $b[8]=chr((ord($b[8])&0x3f)|0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));
    }
}