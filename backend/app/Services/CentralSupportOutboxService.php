<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Local, non-network support ticket preparation. Ticket subjects and bodies
 * are customer-authored, AES-encrypted via Laravel Crypt, not copied from any
 * booking, guest, payment, financial, property or bank record.
 *
 * No central API route or delivery worker is activated by this service.
 * Support remains separate from the 15-day LICENSE verification cadence.
 */
final class CentralSupportOutboxService
{
    public function __construct(private readonly CentralInstallationCompanyScope $scope) {}

    /** @return array{ticket_id:string,state:string} */
    public function queue(User $actor, string $companyId, string $requestId,
        string $subject, string $body): array
    {
        if (config('central_control.support_enabled') !== true) {
            throw new AuthorizationException('HAFZATECH support sending is not enabled.');
        }
        $this->scope->assertCanRead($actor,$companyId);
        if (! Str::isUuid($requestId) || strlen($requestId) !== 36 ||
            trim($subject) !== $subject || trim($body) !== $body ||
            mb_strlen($subject) < 4 || mb_strlen($subject) > 160 ||
            mb_strlen($body) < 3 || mb_strlen($body) > 4000) {
            throw new InvalidArgumentException('Invalid support ticket request or length.');
        }
        $hash=hash('sha256',json_encode([$subject,$body],JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($actor,$companyId,$requestId,$subject,$body,$hash): array {
            DB::select('SELECT pg_advisory_xact_lock(708116, 1117)');
            $this->scope->assertCanRead($actor,$companyId);
            $registration=DB::table('central_installation_activation')
                ->where('singleton_id',1)->lockForUpdate()->first();
            if (! $registration || $registration->company_id !== $companyId) {
                throw new AuthorizationException('Support request company does not match installation.');
            }
            $same=DB::table('central_support_outbox')
                ->where('company_id',$companyId)->where('client_request_id',$requestId)->first();
            if ($same) {
                if (! hash_equals($same->payload_sha256,$hash)) {
                    throw new InvalidArgumentException('Idempotency key used for different support content.');
                }
                return ['ticket_id'=>$same->id,'state'=>$same->delivery_state];
            }
            $id=(string)Str::uuid();
            DB::table('central_support_outbox')->insert([
                'id'=>$id,'company_id'=>$companyId,
                'installation_id'=>$registration->installation_id,
                'created_by'=>$actor->getKey(),
                'client_request_id'=>$requestId,
                'payload_sha256'=>$hash,
                'encrypted_subject'=>Crypt::encryptString($subject),
                'encrypted_body'=>Crypt::encryptString($body),
                'delivery_state'=>'queued',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            return ['ticket_id'=>$id,'state'=>'queued'];
        });
    }
}
