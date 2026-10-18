<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * INTERNAL read-only pre-enrollment checklist, NOT an account activator.
 * Requires a current GLOBAL, MFA-authenticated vendor staff.create grant.
 * A role approved by two staff members still cannot activate a pending user.
 * Never exposes credentials, MFA ciphertext, invitation tokens or PII.
 * No HTTP route and no production activation release in this service.
 */
final class CentralStaffActivationReadiness
{
    private const REMAINING_REQUIREMENTS = [
        'out_of_band_identity_verification',
        'recipient_controlled_password_enrollment',
        'operator_kms_backed_mfa_enrollment',
        'independent_activation_authorization'
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffAccess $access,
        private readonly bool $enabled=false
    ) {}

    /** @return array{staff_id:string,status:string,approved_role_count:int,activation_allowed:bool,remaining_requirements:array<int,string>} */
    public function inspect(string $bearer,string $targetId):array
    {
        if(!$this->enabled ||
            getenv('CENTRAL_STAFF_ACTIVATION_REVIEW_ENABLED')!=='true') {
            throw new RegistrationError('staff_activation_review_not_enabled',503);
        }
        if(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][0-9a-f]{3}-[a-f0-9]{12}$/D',
            $targetId)!==1) {
            throw new RegistrationError('invalid_staff_activation_review',422);
        }
        $actor=$this->sessions->staffFor($bearer);
        if($actor===null ||
            !$this->access->permits($actor,'staff.create',null,true) ||
            $actor===$targetId) {
            throw new RegistrationError('staff_activation_review_denied');
        }
        $q=$this->db->prepare(
            "SELECT u.id,u.status,
                (SELECT count(*) FROM central_staff_role_grants g
                 JOIN central_staff_role_decisions d
                   ON d.role_grant_id=g.id AND d.outcome='approved'
                 WHERE g.staff_id=u.id AND g.revoked_at IS NULL)
                   AS independently_approved_roles
             FROM central_staff_users u
             JOIN central_staff_provision_events p
               ON p.target_staff_id=u.id
             WHERE u.id=:target AND u.status='pending_mfa'"
        );
        $q->execute(['target'=>$targetId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row) {
            // Fail closed for active, suspended, unknown or unprovenanced
            // staff identities; don't treat a direct SQL grant as approval.
            throw new RegistrationError('staff_activation_review_unavailable',404);
        }
        return [
            'staff_id'=>(string)$row['id'],
            'status'=>'pending_mfa',
            'approved_role_count'=>(int)$row['independently_approved_roles'],
            'activation_allowed'=>false,
            'remaining_requirements'=>self::REMAINING_REQUIREMENTS
        ];
    }

    /** Safe private operator view: display blockers, never an activation button. */
    public static function html(array $record):string
    {
        if(($record['status']??null)!=='pending_mfa' ||
           ($record['activation_allowed']??null)!==false ||
           !is_string($record['staff_id']??null) ||
           preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][0-9a-f]{3}-[a-f0-9]{12}$/D',
               $record['staff_id'])!==1 ||
           !is_int($record['approved_role_count']??null) ||
           $record['approved_role_count']<0 ||
           ($record['remaining_requirements']??null)!==self::REMAINING_REQUIREMENTS) {
            throw new RegistrationError('invalid_staff_activation_review_result');
        }
        $id=htmlspecialchars($record['staff_id'],
            ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return '<section aria-label="Private HAFZATECH activation readiness">'.
            '<h2>Pending staff activation safeguards</h2>'.
            '<p>Activation is NOT authorized. Role approval alone never enables login.</p>'.
            '<dl><dt>Staff ID</dt><dd>'.$id.'</dd>'.
            '<dt>Account status</dt><dd>pending_mfa</dd>'.
            '<dt>Independently approved current roles</dt><dd>'.
            $record['approved_role_count'].'</dd></dl>'.
            '<p>Required before any separately approved activation:</p>'.
            '<ol><li>Verify recipient identity out of band</li>'.
            '<li>Enroll a recipient-controlled password through a trusted channel</li>'.
            '<li>Enroll MFA using an operator-approved KMS/vault</li>'.
            '<li>Obtain independent authorization to change account status</li></ol>'.
            '<p>No credentials, enrollment link or activation action are available.</p>'.
            '</section>';
    }
}
