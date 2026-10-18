<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralSupportReplyPullTransport;
use App\Services\CentralSupportOutboxService;
use Hafzatech\Signing\Ed25519SupportReplyPageCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once dirname(__DIR__,3).'/central/signing/Ed25519SupportReplyPageCodec.php';

class CentralSupportReplyPullTransportRoundtripTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_signed_client_proofs_and_vendor_signed_page_deliver_exactly_once():void
    {
        config()->set('central_control.activation_enabled',true);
        config()->set('central_control.support_enabled',true);
        config()->set('central_control.support_reply_pull_enabled',true);
        $company=Company::create(['name'=>'Verified Pull Venue','slug'=>'verified-pull']);
        $owner=User::create(['name'=>'Pull Owner','email'=>'verified-pull@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        $customer=(string)Str::uuid();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'customer_id'=>$customer,'approved_domain'=>'pull.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        $ticket=app(CentralSupportOutboxService::class)->queue(
            $owner,$company->id,(string)Str::uuid(),
            'Support request','Please advise how to configure hall sessions.'
        )['ticket_id'];
        DB::table('central_support_outbox')->where('id',$ticket)->update([
            'delivery_state'=>'sent','central_ticket_number'=>'HAF-00000421',
        ]);
        $pair=sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys',[
            'support-test-key'=>rtrim(strtr(
                base64_encode(sodium_crypto_sign_publickey($pair)),'+/','-_'),'='),
        ]);
        $validProof=false;
        $endpoint='https://lichall.hafzatech.com/api/v1/support/replies/pull';
        Http::fake([$endpoint=>function($request)use($identity,$customer,$ticket,$pair,&$validProof){
            $d=$request->data();
            $pub=base64_decode(strtr($identity['public_key'],'-_','+/'),true);
            $check=implode("\n",[
                'HAFZATECH-INSTALL-CHECK-V1',$identity['installation_id'],$customer,
                $d['request_time'],$d['nonce'],(string)$d['known_revision'],
            ]);
            $changes=implode("\n",[
                'HAFZATECH-SUPPORT-CHANGES-V1',$identity['installation_id'],$customer,
                (string)$d['cursor'],(string)$d['limit'],$d['nonce'],
            ]);
            $validProof=$d['cursor']===0 && $d['limit']===50 &&
                sodium_crypto_sign_verify_detached(
                    base64_decode(strtr($d['signature'],'-_','+/'),true),$check,$pub) &&
                sodium_crypto_sign_verify_detached(
                    base64_decode(strtr($d['cursor_signature'],'-_','+/'),true),$changes,$pub);
            $page=['api_version'=>'v1','next_cursor'=>19,'messages'=>[[
                'event_id'=>19,'message_id'=>(string)Str::uuid(),
                'client_ticket_id'=>$ticket,'ticket_number'=>'HAF-00000421',
                'sender'=>'HAFZATECH Support',
                'body'=>'Here is your hall configuration help.',
                'occurred_at'=>'2026-10-17T19:30:00Z',
            ]]];
            return Http::response([
                'api_version'=>'v1','signed_page'=>Ed25519SupportReplyPageCodec::sign(
                    $page,$customer,$identity['installation_id'],$d['nonce'],0,
                    'support-test-key',sodium_crypto_sign_secretkey($pair)
                ),
            ],200);
        }]);
        $r=app(CentralSupportReplyPullTransport::class)->poll();
        $this->assertTrue($validProof);
        $this->assertSame(['state'=>'received','inserted'=>1,'next_cursor'=>19],$r);
        $this->assertDatabaseCount('central_support_reply_inbox',1);
        $this->assertSame(19,(int)DB::table('central_support_reply_cursor')->value('last_event_id'));
        $this->assertDatabaseCount('central_license_check_state',0);
        $this->assertDatabaseCount('finance_journals',0);
        Http::assertSentCount(1);
    }
}
