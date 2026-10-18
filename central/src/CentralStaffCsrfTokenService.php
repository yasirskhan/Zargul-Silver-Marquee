<?php
declare(strict_types=1);
namespace Hafzatech\Central;

use PDO;

/**
 * Internal double-secret CSRF primitive for centrally authenticated staff.
 * The bearer is intended for a Secure, HttpOnly cookie; the independent
 * CSRF proof is returned only through a protected same-origin response.
 * The eventual HTTP controller must ALSO validate Origin/Fetch Metadata,
 * TLS, cookie settings and authentication routes. No routes enabled here.
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
        $q = $this->db->prepare(
            "UPDATE central_staff_sessions SET csrf_hash=decode(:hash,'hex')
             WHERE token_hash=decode(:session,'hex') AND revoked_at IS NULL
               AND expires_at>now() AND idle_until>now()
             RETURNING id"
        );
        $q->execute(['hash'=>hash('sha256',$proof),
            'session'=>hash('sha256',$sessionBearer)]);
        return $q->fetchColumn()===false ? null : $proof;
    }

    public function accepts(string $sessionBearer,string $submitted): bool
    {
        if(!preg_match('/^[0-9a-f]{64}$/D',$sessionBearer) ||
           !preg_match('/^[0-9a-f]{64}$/D',$submitted))return false;
        if($this->sessions->staffFor($sessionBearer)===null)return false;
        $q=$this->db->prepare(
            "SELECT encode(csrf_hash,'hex') FROM central_staff_sessions
             WHERE token_hash=decode(:session,'hex') AND revoked_at IS NULL
               AND expires_at>now() AND idle_until>now()"
        );
        $q->execute(['session'=>hash('sha256',$sessionBearer)]);
        $stored=$q->fetchColumn();
        return is_string($stored) && hash_equals($stored,hash('sha256',$submitted));
    }
}
