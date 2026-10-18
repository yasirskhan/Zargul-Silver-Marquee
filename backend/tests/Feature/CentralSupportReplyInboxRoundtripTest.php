<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralSupportReplyInboxService;
use App\Services\CentralSupportReplyInboxReader;
use App\Services\CentralSupportOutboxService;
use Hafzatech\Signing\Ed25519SupportReplyPageCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

require_once dirname(__DIR__,3).'/central/signing/Ed25519SupportReplyPageCodec.php';

class CentralSupportReplyInboxRoundtripTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_vendor_reply_is_durably_encrypted_scoped_and_read_once():void
    {
        config()->set('central_control.activation_enabled',true);
        config()->set('central_control.support_enabled',true);
        config()->set('central_control.support_reply_pull_enabled',true);
        $company=Company::create(['name'=>'Inbox Security Venue','slug'=>'inbox-security']);
        $owner=User::create(['name'=>'Inbox Owner','email'=>'inbox-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        $customer=(string)Str::uuid();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'company_id'=>null,'installation_id'=>$identity['installation_id'],
            'customer_id'=>$customer,'approved_domain'=>'inbox.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        $ticket=app(CentralSupportOutboxService::class)->queue(
            $owner,$company->id,(string)Str::uuid(),'Help with my calendar',
            'Please help with an ordinary operating question.'
        );
        DB::table('central_support_outbox')->where('id',$ticket['ticket_id'])
            ->update(['delivery_state'=>'sent','central_ticket_number'=>'HAF-00000021']);
        $pair=sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys',[
            'inbox-signer'=>rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($pair)),'+/','-_'),'='),
        ]);
        $nonce=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $id=(string)Str::uuid();
        $page=['api_version'=>'v1','next_cursor'=>42,'messages'=>[
            ['event_id'=>42,'client_ticket_id'=>$ticket['ticket_id'],
                'message_id'=>$id,'ticket_number'=>'HAF-00000021',
                'sender'=>'HAFZATECH Support',
                'body'=>'Here are the approved setup steps.',
                'occurred_at'=>'2026-10-17T19:30:00Z'],
        ]];
        $envelope=Ed25519SupportReplyPageCodec::sign($page,$customer,$identity['installation_id'],
            $nonce,0,'inbox-signer',sodium_crypto_sign_secretkey($pair));
        $result=app(CentralSupportReplyInboxService::class)->accept($envelope,$nonce,0);
        $this->assertSame(['inserted'=>1,'next_cursor'=>42],$result);
        $row=DB::table('central_support_reply_inbox')->first();
        $this->assertStringNotContainsString('approved setup steps',$row->encrypted_body);
        $this->assertSame('Here are the approved setup steps.',Crypt::decryptString($row->encrypted_body));
        $this->assertSame(42,(int)DB::table('central_support_reply_cursor')->value('last_event_id'));
        $reader=app(CentralSupportReplyInboxReader::class);
        $this->assertSame(1,$reader->unreadCount($owner,$company->id));
        $this->assertSame('Here are the approved setup steps.',$reader->list($owner,$company->id)[0]['body']);
        $this->assertTrue($reader->markRead($owner,$company->id,$row->id));
        $this->assertFalse($reader->markRead($owner,$company->id,$row->id));
        $this->assertSame(0,$reader->unreadCount($owner,$company->id));
        try {
            app(CentralSupportReplyInboxService::class)->accept($envelope,$nonce,0);
            $this->fail('Replayed support reply advanced cursor.');
        } catch(InvalidArgumentException $expected) {}
        $this->assertDatabaseCount('central_support_reply_inbox',1);
        $this->assertDatabaseCount('finance_journals',0);
        $this->assertDatabaseCount('central_license_check_state',0);
    }
}
