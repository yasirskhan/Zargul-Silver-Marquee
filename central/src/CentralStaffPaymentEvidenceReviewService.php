<?php
declare(strict_types=1);

namespace Hafzatech\Central;

use PDO;
use Throwable;

/**
 * INTERNAL claim review. A reviewed screenshot/reference is NEVER payment
 * verification, never a verified settlement, and never licensing authority.
 * Reviewer comes from a real live vendor MFA session with customer-scoped
 * billing.payment.verify. No public route is enabled for this class.
 */
final class CentralStaffPaymentEvidenceReviewService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CentralStaffSessionService $sessions,
        private readonly CentralStaffSessionAuthorization $authorization
    ) {}

    /** @return array{evidence_id:string,decision:string,reviewed_by:string} */
    public function decide(string $vendorSessionBearer,string $evidenceId,
        string $decision,string $reason): array
    {
        $reason=trim($reason);
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$evidenceId)!==1
            || !in_array($decision,['reviewed','rejected'],true)
            || strlen($reason)<12 || strlen($reason)>400) {
            throw new RegistrationError('invalid_payment_evidence_review',422);
        }
        $actor=$this->sessions->staffFor($vendorSessionBearer);
        if ($actor===null) throw new RegistrationError('staff_session_required');
        $this->db->beginTransaction();
        try {
            // Lock invoice before claim, using the same invoice-first order
            // as independent payment and signing eligibility operations.
            $lookup=$this->db->prepare(
                'SELECT invoice_id FROM central_vendor_payment_evidence WHERE id=:id'
            );
            $lookup->execute(['id'=>$evidenceId]);
            $invoiceId=$lookup->fetchColumn();
            if (!is_string($invoiceId)) {
                throw new RegistrationError('payment_evidence_not_found');
            }
            $invoiceLock=$this->db->prepare(
                "SELECT i.customer_id FROM central_vendor_invoices i
                 JOIN vendor_customers c ON c.id=i.customer_id AND c.status='active'
                 WHERE i.id=:invoice AND i.status='issued' FOR UPDATE OF i"
            );
            $invoiceLock->execute(['invoice'=>$invoiceId]);
            $customerId=$invoiceLock->fetchColumn();
            if (!is_string($customerId)) {
                throw new RegistrationError('payment_evidence_not_found');
            }
            if (!$this->authorization->permits(
                $vendorSessionBearer,'billing.payment.verify',$customerId)) {
                throw new RegistrationError('billing_role_required');
            }
            $lock=$this->db->prepare(
                'SELECT id,status FROM central_vendor_payment_evidence
                 WHERE id=:id AND invoice_id=:invoice FOR UPDATE'
            );
            $lock->execute(['id'=>$evidenceId,'invoice'=>$invoiceId]);
            $claim=$lock->fetch(PDO::FETCH_ASSOC);
            if (!$claim) throw new RegistrationError('payment_evidence_not_found');
            if ($claim['status']!=='pending') {
                // An exact replay is idempotent; another person's decision or
                // different reason must not silently overwrite the review.
                $existing=$this->db->prepare(
                    'SELECT reviewer_id,decision,reason
                     FROM central_vendor_payment_evidence_reviews WHERE evidence_id=:id'
                );
                $existing->execute(['id'=>$evidenceId]);
                $prior=$existing->fetch(PDO::FETCH_ASSOC);
                if (!$prior || $prior['reviewer_id']!==$actor ||
                    $prior['decision']!==$decision || $prior['reason']!==$reason) {
                    throw new RegistrationError('payment_evidence_already_decided');
                }
                $this->db->commit();
                return ['evidence_id'=>$evidenceId,'decision'=>$decision,'reviewed_by'=>$actor];
            }
            $audit=$this->db->prepare(
                'INSERT INTO central_vendor_payment_evidence_reviews
                    (evidence_id,invoice_id,reviewer_id,decision,reason)
                 VALUES(:evidence,:invoice,:reviewer,:decision,:reason)'
            );
            $audit->execute(['evidence'=>$evidenceId,'invoice'=>$invoiceId,
                'reviewer'=>$actor,'decision'=>$decision,'reason'=>$reason]);
            $update=$this->db->prepare(
                'UPDATE central_vendor_payment_evidence SET status=:decision
                 WHERE id=:evidence AND status=\'pending\''
            );
            $update->execute(['decision'=>$decision,'evidence'=>$evidenceId]);
            if ($update->rowCount()!==1) {
                throw new RegistrationError('payment_evidence_already_decided');
            }
            $this->db->commit();
            return ['evidence_id'=>$evidenceId,'decision'=>$decision,'reviewed_by'=>$actor];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
