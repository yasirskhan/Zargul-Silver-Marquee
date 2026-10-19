<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Bounded vendor customer discovery. Every result is authorized against
 * the current MFA staff session's live company-scoped capability.
 * No installation, financial, contract or credential data is retrieved.
 */
final class CentralStaffCustomerDirectory
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array<int,array{id:string,legal_name:string}> */
    public function search(string $bearer,string $prefix='',int $limit=20):array
    {
        if(strlen($prefix)>64 || preg_match('/[\x00-\x1F\x7F]/',$prefix)===1 ||
            $limit<1 || $limit>20) {
            throw new RegistrationError('invalid_customer_directory_request',422);
        }
        $actor=$this->sessions->staffFor($bearer);
        if($actor===null)throw new RegistrationError('staff_session_required');
        $q=$this->db->prepare(
            "SELECT c.id,c.legal_name
             FROM vendor_customers c
             WHERE c.status='active'
               AND starts_with(lower(c.legal_name),lower(:prefix))
               AND EXISTS (
                 SELECT 1 FROM central_staff_role_grants g
                 WHERE g.staff_id=:actor AND g.revoked_at IS NULL
                   AND g.role IN ('billing_officer','license_officer',
                     'license_supervisor','support_agent','support_supervisor')
                   AND (g.customer_scope_id IS NULL OR g.customer_scope_id=c.id)
               )
             ORDER BY c.legal_name,c.id LIMIT :max"
        );
        $q->bindValue(':actor',$actor,PDO::PARAM_STR);
        $q->bindValue(':prefix',trim($prefix),PDO::PARAM_STR);
        $q->bindValue(':max',$limit,PDO::PARAM_INT);
        $q->execute();
        $out=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $id=(string)$row['id'];
            if(!$this->authorization->permits($bearer,'billing.invoice.read',$id) &&
               !$this->authorization->permits($bearer,'license.read_status',$id) &&
               !$this->authorization->permits($bearer,'support.ticket.read',$id)) {
                continue;
            }
            $out[]=['id'=>$id,'legal_name'=>(string)$row['legal_name']];
        }
        return $out;
    }
}
