<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use Closure;
use PDO;
use Throwable;

/**
 * Separate HAFZATECH customer enrollment DNS ownership check. The supplied
 * resolver must be a trusted server-side DNS resolver, never a client-provided
 * TXT record, HTTP body or impersonated staff-session value.
 *
 * Proof format: TXT at _hafzatech-verify.<domain>
 * value: "hafzatech-verify=<random 32-byte base64url>"
 * Only SHA256 of proof is stored; proof is unrelated to enrollment token.
 */
final class DomainOwnershipVerifier
{
    /** @param Closure(string):array<int,string> $resolveTxt */
    public function __construct(private readonly PDO $db, private readonly Closure $resolveTxt) {}

    public function verify(string $enrollmentId): bool
    {
        $q=$this->db->prepare(
            "SELECT e.id,e.customer_id,e.approved_domain,p.proof_sha256
             FROM installation_enrollments e
             JOIN vendor_customers c ON c.id=e.customer_id AND c.status='active'
             JOIN central_installation_domain_proofs p ON p.enrollment_id=e.id
             WHERE e.id=:enrollment AND e.expires_at>now()
               AND e.claimed_at IS NULL AND p.expires_at>now()"
        );
        $q->execute(['enrollment'=>$enrollmentId]);
        $record=$q->fetch(PDO::FETCH_ASSOC);
        if (!$record) return false;
        if (!self::validDomain($record['approved_domain'])) return false;

        $txt=($this->resolveTxt)('_hafzatech-verify.'.$record['approved_domain']);
        if (!is_array($txt) || count($txt)>100) return false;
        $seen=false;
        foreach($txt as $recordText){
            if (!is_string($recordText) ||
                !preg_match('/^hafzatech-verify=([A-Za-z0-9_-]{43})$/D',$recordText,$matches)){
                continue;
            }
            $raw=base64_decode(strtr($matches[1],'-_','+/'),true);
            if ($raw===false || strlen($raw)!==32) continue;
            if (hash_equals(trim($record['proof_sha256']),hash('sha256',$raw))){
                $seen=true;
                break;
            }
        }
        if(!$seen) return false;

        $this->db->beginTransaction();
        try{
            $lock=$this->db->prepare(
                "SELECT e.id,e.customer_id,e.approved_domain,p.proof_sha256,
                        e.domain_verified_at
                 FROM installation_enrollments e
                 JOIN vendor_customers c ON c.id=e.customer_id AND c.status='active'
                 JOIN central_installation_domain_proofs p ON p.enrollment_id=e.id
                 WHERE e.id=:id AND e.expires_at>now() AND e.claimed_at IS NULL
                   AND p.expires_at>now() FOR UPDATE OF e,p"
            );
            $lock->execute(['id'=>$enrollmentId]);
            $current=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$current || $current['approved_domain']!==$record['approved_domain'] ||
               !hash_equals(trim($current['proof_sha256']),trim($record['proof_sha256']))){
                $this->db->rollBack();
                return false;
            }
            if(!$current['domain_verified_at']){
                $stmt=$this->db->prepare(
                    "UPDATE installation_enrollments SET domain_verified_at=now() WHERE id=:id"
                );
                $stmt->execute(['id'=>$enrollmentId]);
                $stmt=$this->db->prepare(
                    "UPDATE central_installation_domain_proofs SET verified_at=now()
                     WHERE enrollment_id=:id"
                );
                $stmt->execute(['id'=>$enrollmentId]);
                $stmt=$this->db->prepare(
                    "INSERT INTO central_domain_verification_audit
                     (customer_id,enrollment_id,verified_domain)
                     VALUES(:customer,:enrollment,:domain)"
                );
                $stmt->execute(['customer'=>$current['customer_id'],
                    'enrollment'=>$enrollmentId,'domain'=>$current['approved_domain']]);
            }
            $this->db->commit();
            return true;
        }catch(Throwable $error){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $error;
        }
    }

    private static function validDomain(string $value): bool
    {
        return strlen($value)<=253 && $value===strtolower($value)
            && filter_var($value,FILTER_VALIDATE_IP)===false
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$value)===1;
    }
}
