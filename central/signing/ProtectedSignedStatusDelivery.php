<?php
declare(strict_types=1);

namespace Hafzatech\Signing;

use Hafzatech\Central\RegistrationError;
use Hafzatech\Central\SignedGrantDeliveryService;

/**
 * Isolated KMS/HSM worker response. No public HTTP import and no key custody
 * in the central web application. Caller must obtain an actual private key
 * through a protected signing worker after operator provisioning.
 *
 * Requires BOTH independent default-OFF delivery/signing release gates.
 * The status service itself authenticates the fresh installation proof and
 * consumes the one-time nonce before any signed receipt is generated.
 */
final class ProtectedSignedStatusDelivery
{
    public function __construct(private readonly SignedGrantDeliveryService $grants) {}

    /** @param array<string,mixed> $signedRequest
     * @return array<string,mixed>
     */
    public function build(array $signedRequest,string $keyId,string $privateKey): array
    {
        if(getenv('CENTRAL_SIGNED_DELIVERY_ENABLED')!=='true') {
            throw new RegistrationError('signed_delivery_not_enabled',503);
        }
        $reply=$this->grants->fetch($signedRequest);
        $claim=[
            'api_version'=>'v1',
            'issuer'=>'HAFZATECH',
            'installation_id'=>$reply['registered_installation_id'],
            'customer_id'=>$signedRequest['customer_id'],
            'nonce'=>$reply['nonce'],
            'status'=>$reply['status'],
            'revision'=>$reply['revision'],
            'issued_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $reply['signed_receipt']=Ed25519StatusReceiptCodec::sign(
            $claim,$keyId,$privateKey
        );
        return $reply;
    }
}
