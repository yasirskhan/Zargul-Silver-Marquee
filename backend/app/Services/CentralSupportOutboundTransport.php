<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Explicit opt-in outbound ticket transport for one already owner-authorized
 * encrypted support outbox entry per run. This worker cannot create customer
 * tickets, approve money, read venue bookings or set license entitlements.
 */
final class CentralSupportOutboundTransport
{
    /** @return array{state:string} */
    public function sendOne(): array
    {
        if(config('central_control.support_enabled')!==true ||
           config('central_control.support_delivery_enabled')!==true) {
            return ['state'=>'disabled'];
        }
        try {
            return DB::transaction(function (): array {
                DB::select('SELECT pg_advisory_xact_lock(708116, 1119)');
                $binding=DB::table('central_installation_activation')
                    ->where('singleton_id',1)->first();
                $identity=DB::table('central_installation_identity')
                    ->where('singleton_id',1)->first();
                if (!$binding || !$identity || !$binding->company_id ||
                    $binding->installation_id!==$identity->installation_id ||
                    $binding->status!=='registered_pending_license') {
                    return ['state'=>'not_enrolled'];
                }
                $ticket=DB::table('central_support_outbox')
                    ->where('company_id',$binding->company_id)
                    ->where('installation_id',$identity->installation_id)
                    ->where('delivery_state','queued')
                    ->orderBy('created_at')->lockForUpdate()->first();
                if(!$ticket)return ['state'=>'idle'];
                $subject=Crypt::decryptString($ticket->encrypted_subject);
                $body=Crypt::decryptString($ticket->encrypted_body);
                if(!hash_equals($ticket->payload_sha256,
                    hash('sha256',json_encode([$subject,$body],JSON_THROW_ON_ERROR)))) {
                    throw new InvalidArgumentException('Encrypted support payload was modified.');
                }
                $secret=Crypt::decryptString($identity->encrypted_private_key);
                $private=base64_decode(strtr($secret,'-_','+/'),true);
                if(!is_string($private)||
                   strlen($private)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES||
                   self::encode($private)!==$secret){
                    throw new InvalidArgumentException('Invalid installed client signing key.');
                }
                $nonce=self::encode(random_bytes(32));
                $time=now('UTC')->format('Y-m-d\TH:i:s\Z');
                $revision=(int)(DB::table('central_signed_license_cache')
                    ->where('singleton_id',1)->value('revision')??0);
                $statusMessage=implode("\n",[
                    'HAFZATECH-INSTALL-CHECK-V1',$identity->installation_id,
                    $binding->customer_id,$time,$nonce,(string)$revision,
                ]);
                $supportMessage=implode("\n",[
                    'HAFZATECH-SUPPORT-CREATE-V1',$identity->installation_id,
                    $binding->customer_id,$ticket->id,$nonce,
                    hash('sha256',$subject),hash('sha256',$body),
                ]);
                $payload=[
                    'api_version'=>'v1',
                    'installation_id'=>$identity->installation_id,
                    'customer_id'=>$binding->customer_id,
                    'request_time'=>$time,
                    'nonce'=>$nonce,
                    'known_revision'=>$revision,
                    'signature'=>self::encode(sodium_crypto_sign_detached($statusMessage,$private)),
                    'client_ticket_id'=>$ticket->id,
                    'subject'=>$subject,'body'=>$body,
                    'content_signature'=>self::encode(sodium_crypto_sign_detached($supportMessage,$private)),
                ];
                $response=Http::acceptJson()->asJson()
                    ->withOptions(['allow_redirects'=>false])
                    ->connectTimeout(3)->timeout(8)
                    ->post('https://lichall.hafzatech.com/api/v1/support/tickets',$payload);
                if(!in_array($response->status(),[200,201],true))
                    throw new InvalidArgumentException('Trusted support service unavailable.');
                $answer=$response->json();
                if(!is_array($answer)||($answer['api_version']??null)!=='v1'||
                   ($answer['client_ticket_id']??null)!==$ticket->id||
                   ($answer['customer_id']??null)!==$binding->customer_id||
                   ($answer['status']??null)!=='new'||
                   !is_string($answer['ticket_id']??null)||
                   !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$answer['ticket_id'])||
                   !is_string($answer['ticket_number']??null)||
                   !preg_match('/^HAF-[0-9]{8,}$/D',$answer['ticket_number'])) {
                    throw new InvalidArgumentException('Central support acknowledgment binding invalid.');
                }
                DB::table('central_support_outbox')->where('id',$ticket->id)
                    ->where('company_id',$binding->company_id)->update([
                        'delivery_state'=>'sent',
                        'central_ticket_number'=>$answer['ticket_number'],
                        'delivered_at'=>now(),
                        'updated_at'=>now(),
                    ]);
                return ['state'=>'sent'];
            });
        }catch(Throwable) {
            // Queue is unchanged on failed HTTPS, invalid ack or missing
            // key. Customer-authored body and credentials are never logged.
            return ['state'=>'error'];
        }
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
    }
}
