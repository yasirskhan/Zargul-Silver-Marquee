<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Internal password step for future vendor-only central staff login.
 * Never accepts a VenueDesk tenant user, creates a session/token, issues
 * MFA assertions, or exposes whether an email address is registered.
 * A protected login controller still needs IP/account rate limiting, HTTPS
 * secure cookies, KMS-backed TOTP and CSRF before any public rollout.
 */
final class CentralStaffPasswordVerifier
{
    private const DUMMY_HASH = '$2y$10$wHnRYMxXtEjrcf/ySXTeBuUe7lqxJqsVqBY6SVBXJV.IixXFy8EGK';

    public function __construct(private readonly PDO $db) {}

    /** @return string|null Verified active vendor staff UUID, never session access */
    public function verify(string $email,string $password):?string
    {
        $canonical=strtolower(trim($email));
        $validEmail=$canonical===$email && strlen($canonical)<=254 &&
            filter_var($canonical,FILTER_VALIDATE_EMAIL)!==false;
        $row=null;
        if($validEmail){
            $q=$this->db->prepare(
                "SELECT id,password_hash,status,mfa_enrolled_at FROM central_staff_users
                 WHERE email=:email"
            );
            $q->execute(['email'=>$canonical]);
            $row=$q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $hash=$row['password_hash'] ?? self::DUMMY_HASH;
        // Verify a fixed fallback hash for unknown/malformed identities to
        // avoid skipping the expensive KDF on an account-discovery path.
        $matches=password_verify($password,$hash);
        if(!$matches || !$row || $row['status']!=='active' ||
           $row['mfa_enrolled_at']===null)return null;
        return $row['id'];
    }
}
