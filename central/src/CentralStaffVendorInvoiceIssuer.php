<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use Throwable;

/**
 * Vendor-only, gated, MFA Billing Officer issuance of an exact, immutable
 * 6/12-month INVOICE. It does not record settlement, produce a signed grant,
 * deliver payment instructions over HTTP or activate any installation.
 *
 * Must only be called from future origin/CSRF-protected staff routing, never
 * from a customer installation or a user-provided "MFA verified" field.
 */
final class CentralStaffVendorInvoiceIssuer
{
    private const UUID='/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D';

    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization,
        private readonly bool $enabled=false
    ) {}

    /** @return array{invoice_id:string,invoice_number:string,currency:string,total_minor:string,term_months:int,status:string} */
    public function issue(string $staffBearer,string $customerId,string $planVersionId,
        string $receivingAccountId,string $requestId):array
    {
        if(!$this->enabled || getenv('CENTRAL_VENDOR_INVOICE_ISSUANCE_ENABLED')!=='true') {
            throw new RegistrationError('vendor_invoice_issuance_not_enabled',503);
        }
        foreach([$customerId,$planVersionId,$receivingAccountId,$requestId] as $value) {
            if(preg_match(self::UUID,$value)!==1) {
                throw new RegistrationError('invalid_vendor_invoice_request',422);
            }
        }
        $actor=$this->sessions->staffFor($staffBearer);
        if(!is_string($actor) ||
            !$this->authorization->permits($staffBearer,'billing.invoice.issue',$customerId)) {
            throw new RegistrationError('billing_invoice_issuer_required');
        }

        $fingerprint=hash('sha256',json_encode([
            $customerId,$planVersionId,$receivingAccountId
        ],JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try {
            // Serialize same-customer concurrent requests and stable invoice
            // snapshots before comparing idempotency claims. No client amount,
            // currency, plan benefits or bank instructions can be supplied.
            $q=$this->db->prepare(
                "SELECT id FROM vendor_customers
                 WHERE id=:customer AND status='active' FOR UPDATE"
            );
            $q->execute(['customer'=>$customerId]);
            if($q->fetchColumn()!==$customerId) {
                throw new RegistrationError('vendor_invoice_customer_unavailable',404);
            }
            $q=$this->db->prepare(
                'SELECT id,term_months,currency,price_minor,entitlements_snapshot
                 FROM central_vendor_plan_versions WHERE id=:plan FOR SHARE'
            );
            $q->execute(['plan'=>$planVersionId]);
            $plan=$q->fetch(PDO::FETCH_ASSOC);
            if(!$plan || !in_array((int)$plan['term_months'],[6,12],true)) {
                throw new RegistrationError('vendor_invoice_plan_unavailable',404);
            }
            $benefits=json_decode((string)$plan['entitlements_snapshot'],
                true,32,JSON_THROW_ON_ERROR);
            if(!is_array($benefits) || array_is_list($benefits) ||
                !is_int($benefits['max_halls']??null) ||
                !is_int($benefits['max_staff']??null) ||
                $benefits['max_halls']<1 || $benefits['max_staff']<1 ||
                !is_array($benefits['features']??null)) {
                throw new RegistrationError('vendor_invoice_plan_unavailable',404);
            }
            $q=$this->db->prepare(
                "SELECT encode(a.encrypted_instructions,'hex') AS sealed
                 FROM central_vendor_receiving_accounts a
                 JOIN central_customer_provider_assignments m
                   ON m.receiving_account_id=a.id AND m.customer_id=:customer
                 WHERE a.id=:account AND a.status='verified'
                   AND a.provider_kind<>'paypal'
                   AND a.receiving_currency=:currency
                   AND m.enabled=true AND m.effective_from<=now()
                   AND (m.effective_to IS NULL OR m.effective_to>now())
                 FOR SHARE OF a,m"
            );
            $q->execute(['customer'=>$customerId,'account'=>$receivingAccountId,
                'currency'=>$plan['currency']]);
            $sealed=$q->fetchColumn();
            if(!is_string($sealed) || $sealed==='') {
                throw new RegistrationError('vendor_invoice_receiver_unavailable',404);
            }
            if(!$this->authorization->permits(
                $staffBearer,'billing.invoice.issue',$customerId)) {
                throw new RegistrationError('billing_invoice_issuer_required');
            }

            $q=$this->db->prepare(
                'SELECT e.invoice_id,e.issued_by_staff_id,e.request_sha256,
                        i.invoice_number,i.currency,i.total_minor,i.status
                 FROM central_vendor_invoice_issue_events e
                 JOIN central_vendor_invoices i ON i.id=e.invoice_id
                 WHERE e.request_id=:request'
            );
            $q->execute(['request'=>$requestId]);
            $existing=$q->fetch(PDO::FETCH_ASSOC);
            if($existing) {
                if($existing['issued_by_staff_id']!==$actor ||
                    !hash_equals($existing['request_sha256'],$fingerprint)) {
                    throw new RegistrationError('vendor_invoice_request_conflict',409);
                }
                $this->db->commit();
                return [
                    'invoice_id'=>$existing['invoice_id'],
                    'invoice_number'=>$existing['invoice_number'],
                    'currency'=>$existing['currency'],
                    'total_minor'=>(string)$existing['total_minor'],
                    'term_months'=>(int)$plan['term_months'],
                    'status'=>$existing['status'],
                ];
            }

            $invoiceId=self::uuid();
            $number='HTZ-'.strtoupper(str_replace('-','',$invoiceId));
            $q=$this->db->prepare(
                "INSERT INTO central_vendor_invoices
                 (id,customer_id,plan_version_id,invoice_number,currency,
                  total_minor,receiving_account_id,encrypted_instructions_snapshot,
                  status,issued_at,due_at)
                 VALUES(:id,:customer,:plan,:number,:currency,:amount,
                        :receiver,decode(:snapshot,'hex'),'issued',
                        now(),now()+interval '30 days')"
            );
            $q->execute([
                'id'=>$invoiceId,'customer'=>$customerId,'plan'=>$planVersionId,
                'number'=>$number,'currency'=>$plan['currency'],
                'amount'=>$plan['price_minor'],'receiver'=>$receivingAccountId,
                'snapshot'=>$sealed,
            ]);
            $q=$this->db->prepare(
                'INSERT INTO central_vendor_invoice_issue_events
                 (request_id,customer_id,invoice_id,issued_by_staff_id,
                  request_sha256)
                 VALUES(:request,:customer,:invoice,:actor,:digest)'
            );
            $q->execute(['request'=>$requestId,'customer'=>$customerId,
                'invoice'=>$invoiceId,'actor'=>$actor,'digest'=>$fingerprint]);
            $this->db->commit();
            return [
                'invoice_id'=>$invoiceId,'invoice_number'=>$number,
                'currency'=>$plan['currency'],
                'total_minor'=>(string)$plan['price_minor'],
                'term_months'=>(int)$plan['term_months'],
                'status'=>'issued',
            ];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
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
