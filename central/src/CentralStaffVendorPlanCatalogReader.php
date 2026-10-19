<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;

/**
 * Staff-only, bounded plan catalog history. No customer, invoice, bank,
 * payment or signing data. Does not confer create permission.
 */
final class CentralStaffVendorPlanCatalogReader
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function recent(string $staffBearer,int $limit=25):array
    {
        if($limit<1 || $limit>25) {
            throw new RegistrationError('invalid_vendor_plan_catalog_request',422);
        }
        if(!$this->authorization->permits(
            $staffBearer,'plan.read',null)) {
            throw new RegistrationError('vendor_plan_read_denied');
        }
        $q=$this->db->prepare(
            'SELECT id,plan_code,version,term_months,currency,price_minor,created_at
             FROM central_vendor_plan_versions
             ORDER BY created_at DESC,id DESC LIMIT :max'
        );
        $q->bindValue(':max',$limit,PDO::PARAM_INT);
        $q->execute();
        return array_map(static fn(array $row):array=>[
            'plan_version_id'=>$row['id'],
            'plan_code'=>$row['plan_code'],
            'version'=>(int)$row['version'],
            'term_months'=>(int)$row['term_months'],
            'currency'=>$row['currency'],
            'price_minor'=>(string)$row['price_minor'],
            'created_at'=>$row['created_at'],
        ],$q->fetchAll(PDO::FETCH_ASSOC));
    }
}
