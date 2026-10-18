<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use Throwable;

/**
 * INTERNAL recording of independently observed refunds/chargebacks.
 * Does NOT initiate payment, create a provider refund, or verify bank funds.
 * Operational staff must verify the provider/bank reference OUT OF BAND
 * before submitting. No public staff routes or payment gateways are enabled.
 */
final class CentralVendorVerifiedReversalService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array{id:string,settlement_id:string,amount_minor:int,kind:string} */
    public function record(string $vendorSessionBearer, string $settlementId,
        int $amountMinor, string $kind, string $externalReference,
        string $reason): array
    {
        $externalReference=trim($externalReference);
        $reason=trim($reason);
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$settlementId)
            || $amountMinor<=0 || !in_array($kind,['refund','chargeback'],true)
            || !preg_match('~^[A-Za-z0-9][A-Za-z0-9:._/-]{5,119}$~D',$externalReference)
            || strlen($reason)<12 || strlen($reason)>400) {
            throw new RegistrationError('invalid_verified_reversal',422);
        }
        $actor=$this->sessions->staffFor($vendorSessionBearer);
        if ($actor===null)throw new RegistrationError('staff_session_required');

        $this->db->beginTransaction();
        try {
            // Invoice first, then settlement: shared lock order with paid
            // license preparation/finalization serializes billing reversal
            // against future signed entitlement eligibility checks.
            $lookup=$this->db->prepare(
                'SELECT invoice_id FROM central_vendor_verified_settlements WHERE id=:id'
            );
            $lookup->execute(['id'=>$settlementId]);
            $invoice=$lookup->fetchColumn();
            if (!is_string($invoice))throw new RegistrationError('verified_settlement_not_found');
            $invoiceLock=$this->db->prepare(
                'SELECT id FROM central_vendor_invoices WHERE id=:id FOR UPDATE'
            );
            $invoiceLock->execute(['id'=>$invoice]);
            if (!$invoiceLock->fetchColumn())throw new RegistrationError('verified_settlement_not_found');
            $stmt=$this->db->prepare(
                "SELECT s.id,s.amount_minor,i.customer_id
                 FROM central_vendor_verified_settlements s
                 JOIN central_vendor_invoices i ON i.id=s.invoice_id
                 WHERE s.id=:settlement FOR UPDATE OF s"
            );
            $stmt->execute(['settlement'=>$settlementId]);
            $settlement=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$settlement)throw new RegistrationError('verified_settlement_not_found');

            // No caller-provided customer, staff ID or trusted-MFA boolean.
            if (!$this->authorization->permits(
                $vendorSessionBearer,'billing.payment.verify',$settlement['customer_id'])) {
                throw new RegistrationError('billing_role_required');
            }
            $q=$this->db->prepare(
                "SELECT id,amount_minor,kind,reason,recorded_by
                 FROM central_vendor_settlement_reversals
                 WHERE settlement_id=:settlement AND external_reference=:ref"
            );
            $q->execute(['settlement'=>$settlementId,'ref'=>$externalReference]);
            $existing=$q->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if (bccomp((string)$existing['amount_minor'],(string)$amountMinor,0)!==0
                    || $existing['kind']!==$kind || $existing['reason']!==$reason
                    || $existing['recorded_by']!==$actor) {
                    throw new RegistrationError('reversal_reference_conflict');
                }
                $this->db->commit();
                return ['id'=>$existing['id'],'settlement_id'=>$settlementId,
                    'amount_minor'=>$amountMinor,'kind'=>$kind];
            }

            $sum=$this->db->prepare(
                'SELECT COALESCE(SUM(amount_minor),0)
                 FROM central_vendor_settlement_reversals WHERE settlement_id=:id'
            );
            $sum->execute(['id'=>$settlementId]);
            if (bccomp(bcadd((string)$sum->fetchColumn(),(string)$amountMinor,0),
                (string)$settlement['amount_minor'],0)>0) {
                throw new RegistrationError('reversal_exceeds_verified_settlement');
            }
            $id=self::uuid();
            $q=$this->db->prepare(
                "INSERT INTO central_vendor_settlement_reversals
                 (id,settlement_id,amount_minor,kind,external_reference,reason,recorded_by)
                 VALUES(:id,:settlement,:amount,:kind,:ref,:reason,:actor)"
            );
            $q->execute(['id'=>$id,'settlement'=>$settlementId,
                'amount'=>$amountMinor,'kind'=>$kind,'ref'=>$externalReference,
                'reason'=>$reason,'actor'=>$actor]);
            $this->db->commit();
            return ['id'=>$id,'settlement_id'=>$settlementId,
                'amount_minor'=>$amountMinor,'kind'=>$kind];
        } catch (Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    private static function uuid(): string
    {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);
        $b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.
            substr($h,16,4).'-'.substr($h,20);
    }
}
