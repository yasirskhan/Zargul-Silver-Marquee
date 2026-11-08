<?php
declare(strict_types=1);
namespace Hafzatech\Central;

use PDO;

/**
 * Internal double-secret CSRF primitive for centrally authenticated staff.
 * The bearer is intended for a Secure, HttpOnly cookie; the independent
 * CSRF proof is returned only through a protected same-origin response.
 * Multiple bounded proofs remain valid per live session so ordinary page
 * navigation does not invalidate other open tabs. Revocation and expiry
 * still invalidate every proof through the session row.
 */
final class CentralStaffCsrfTokenService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions
    ) {}

    public function issue(string $sessionBearer): ?string
    {
        if ($this->sessions->staffFor($sessionBearer) === null) return null;
        $proof = bin2hex(random_bytes(32));
        $hash = hash('sha256', $proof);
        $this->db->beginTransaction();
        try {
        $q = $this->db->prepare(
            "UPDATE central_staff_sessions SET csrf_hash=decode(:hash,'hex')
             WHERE token_hash=decode(:session,'hex') AND revoked_at IS NULL
               AND expires_at>now() AND idle_until>now()
             RETURNING id"
        );
        $q->execute(['hash'=>$hash,'session'=>hash('sha256',$sessionBearer)]);
        $id = $q->fetchColumn();
        if ($id === false) { $this->db->rollBack(); return null; }
        $this->db->prepare(
            "INSERT INTO central_staff_csrf_proofs(session_id,proof_hash)
             VALUES(:id,decode(:hash,'hex')) ON CONFLICT DO NOTHING"
        )->execute(['id'=>$id,'hash'=>$hash]);
        // Bounded history. A browser never needs more than a few concurrent
        // pages; old proofs age out without weakening the session binding.
        $this->db->prepare(
            "DELETE FROM central_staff_csrf_proofs WHERE session_id=:id
             AND proof_hash NOT IN (
                 SELECT proof_hash FROM central_staff_csrf_proofs
                 WHERE session_id=:id ORDER BY created_at DESC LIMIT 20)"
        )->execute(['id'=>$id]);
        $this->db->commit();
        return $proof;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function accepts(string $sessionBearer,string $submitted): bool
    {
        if(!preg_match('/^[0-9a-f]{64}$/D',$sessionBearer) ||
           !preg_match('/^[0-9a-f]{64}$/D',$submitted))return false;
        if($this->sessions->staffFor($sessionBearer)===null)return false;
        $q=$this->db->prepare(
            "SELECT 1 FROM central_staff_sessions s
             WHERE s.token_hash=decode(:session,'hex') AND s.revoked_at IS NULL
               AND s.expires_at>now() AND s.idle_until>now()
               AND (s.csrf_hash=decode(:hash,'hex') OR EXISTS (
                 SELECT 1 FROM central_staff_csrf_proofs p
                 WHERE p.session_id=s.id AND p.proof_hash=decode(:hash,'hex')))
        ");
        $q->execute(['session'=>hash('sha256',$sessionBearer),
            'hash'=>hash('sha256',$submitted)]);
        return $q->fetchColumn()!==false;
    }
}