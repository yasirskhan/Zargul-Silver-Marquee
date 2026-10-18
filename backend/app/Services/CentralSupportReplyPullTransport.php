<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Default-OFF independently scheduled support reply poll. The only way the
 * cursor moves is after the pinned HAFZATECH signature, binding and message
 * checks in CentralSupportReplyInboxService.accept().
 * NEVER changes licensed status, billing or the 15-day license check clock.
 */
final class CentralSupportReplyPullTransport
{
    public function __construct(private readonly CentralSupportReplyInboxService $inbox) {}

    /** @return array{state:string,inserted?:int,next_cursor?:int} */
    public function poll():array
    {
        if(config('central_control.support_enabled')!==true ||
            config('central_control.support_reply_pull_enabled')!==true)
            return ['state'=>'disabled'];
        try {
            $binding=DB::table('central_installation_activation')->where('singleton_id',1)->first();
            $identity=DB::table('central_installation_identity')->where('singleton_id',1)->first();
            if(!$binding || !$identity || !$binding->company_id ||
                $binding->installation_id!==$identity->installation_id ||
                $binding->status!=='registered_pending_license')return ['state'=>'not_enrolled'];
            $cursor=(int)(DB::table('central_support_reply_cursor')
                ->where('singleton_id',1)->value('last_event_id')??0);
            $secret=Crypt::decryptString($identity->encrypted_private_key);
            $key=base64_decode(strtr($secret,'-_','+/'),true);
            if(!is_string($key)||
                strlen($key)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES||
                self::b64($key)!==$secret)
                return ['state'=>'error'];
            $nonce=self::b64(random_bytes(32));
            $time=now('UTC')->format('Y-m-d\TH:i:s\Z');
            $revision=(int)(DB::table('central_signed_license_cache')
                ->where('singleton_id',1)->value('revision')??0);
            $limit=50;
            $status=implode("\n",[
                'HAFZATECH-INSTALL-CHECK-V1',$identity->installation_id,
                $binding->customer_id,$time,$nonce,(string)$revision,
            ]);
            $changes=implode("\n",[
                'HAFZATECH-SUPPORT-CHANGES-V1',$identity->installation_id,
                $binding->customer_id,(string)$cursor,(string)$limit,$nonce,
            ]);
            $payload=[
                'installation_id'=>$identity->installation_id,
                'customer_id'=>$binding->customer_id,
                'request_time'=>$time,'nonce'=>$nonce,
                'known_revision'=>$revision,
                'signature'=>self::b64(sodium_crypto_sign_detached($status,$key)),
                'cursor'=>$cursor,'limit'=>$limit,
                'cursor_signature'=>self::b64(sodium_crypto_sign_detached($changes,$key)),
            ];
            $reply=Http::acceptJson()->asJson()
                ->withOptions(['allow_redirects'=>false])
                ->connectTimeout(3)->timeout(8)
                ->post('https://lichall.hafzatech.com/api/v1/support/replies/pull',$payload);
            if($reply->status()!==200) return ['state'=>'error'];
            $data=$reply->json();
            if(!is_array($data) || ($data['api_version']??null)!=='v1' ||
                !is_array($data['signed_page']??null))
                return ['state'=>'error'];
            $out=$this->inbox->accept($data['signed_page'],$nonce,$cursor);
            return ['state'=>'received']+$out;
        }catch(Throwable){
            return ['state'=>'error'];
        }
    }

    private static function b64(string $bytes):string{
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }
}
