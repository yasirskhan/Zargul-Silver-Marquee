<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralSupportOutboxService;
use App\Services\CentralSupportOutboundTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralSupportOutboundTransportTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(): array
    {
        config()->set('central_control.activation_enabled',true);
        config()->set('central_control.support_enabled',true);
        $company=Company::create(['name'=>'Support Send Venue','slug'=>'support-send-venue']);
        $owner=User::create(['name'=>'Support Sender',
            'email'=>'support-send-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner',
        ]);
        $id=app(CentralInstallationIdentity::class)->initialize();
        $customer=(string)Str::uuid();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$id['installation_id'],
            'company_id'=>null,'customer_id'=>$customer,
            'approved_domain'=>'support-send.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        $subject='Support ticket from owner';
        $body='Please help configure my venue calendar.';
        $ticket=app(CentralSupportOutboxService::class)->queue(
            $owner,$company->id,(string)Str::uuid(),$subject,$body
        );
        return [$ticket['ticket_id'],$id,$customer,$subject,$body];
    }

    public function test_disabled_support_delivery_never_leaks_queued_ticket_to_network(): void
    {
        [$id]=$this->ticket();
        config()->set('central_control.support_delivery_enabled',false);
        Http::preventStrayRequests();
        $this->assertSame(['state'=>'disabled'],
            app(CentralSupportOutboundTransport::class)->sendOne());
        $this->assertSame('queued',DB::table('central_support_outbox')
            ->where('id',$id)->value('delivery_state'));
        $this->assertSame(0,Artisan::call('venuedesk:send-hafzatech-support'));
        Http::assertNothingSent();
    }

    public function test_opted_in_ticket_is_signed_twice_sent_once_and_not_a_license_check(): void
    {
        [$id,$installation,$customer,$subject,$body]=$this->ticket();
        config()->set('central_control.support_delivery_enabled',true);
        config()->set('central_control.activation_enabled',false);
        $signed=false;
        $endpoint='https://lichall.hafzatech.com/api/v1/support/tickets';
        Http::fake([$endpoint=>function($req)use($id,$installation,$customer,$subject,$body,&$signed){
            $payload=$req->data();
            $this->assertSame($id,$payload['client_ticket_id']);
            $this->assertSame($customer,$payload['customer_id']);
            $this->assertSame($subject,$payload['subject']);
            $this->assertSame($body,$payload['body']);
            $this->assertSame(0,$payload['known_revision']);
            $pub=base64_decode(strtr($installation['public_key'],'-_','+/'),true);
            $sig1=base64_decode(strtr($payload['signature'],'-_','+/'),true);
            $sig2=base64_decode(strtr($payload['content_signature'],'-_','+/'),true);
            $status=implode("\n",[
                'HAFZATECH-INSTALL-CHECK-V1',$installation['installation_id'],
                $customer,$payload['request_time'],$payload['nonce'],'0',
            ]);
            $content=implode("\n",[
                'HAFZATECH-SUPPORT-CREATE-V1',$installation['installation_id'],
                $customer,$id,$payload['nonce'],
                hash('sha256',$subject),hash('sha256',$body),
            ]);
            $signed=sodium_crypto_sign_verify_detached($sig1,$status,$pub) &&
                sodium_crypto_sign_verify_detached($sig2,$content,$pub);
            return Http::response([
                'api_version'=>'v1','ticket_id'=>(string)Str::uuid(),
                'client_ticket_id'=>$id,'customer_id'=>$customer,
                'ticket_number'=>'HAF-00000042','status'=>'new',
            ],201);
        }]);
        $this->assertSame(['state'=>'sent'],
            app(CentralSupportOutboundTransport::class)->sendOne());
        $this->assertTrue($signed);
        $this->assertSame('sent',DB::table('central_support_outbox')
            ->where('id',$id)->value('delivery_state'));
        $this->assertSame('HAF-00000042',DB::table('central_support_outbox')
            ->where('id',$id)->value('central_ticket_number'));
        $this->assertSame(['state'=>'idle'],
            app(CentralSupportOutboundTransport::class)->sendOne());
        Http::assertSentCount(1);
        $this->assertDatabaseCount('central_signed_license_cache',0);
        $this->assertDatabaseCount('central_license_check_state',0);
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_foreign_or_mismatched_ack_never_marks_outbox_sent(): void
    {
        [$id,$installation]=$this->ticket();
        config()->set('central_control.support_delivery_enabled',true);
        Http::fake(['https://lichall.hafzatech.com/api/v1/support/tickets'=>
            Http::response([
                'api_version'=>'v1','ticket_id'=>(string)Str::uuid(),
                'client_ticket_id'=>(string)Str::uuid(),
                'customer_id'=>(string)Str::uuid(),
                'ticket_number'=>'HAF-00000001','status'=>'new',
            ],201),
        ]);
        $this->assertSame(['state'=>'error'],
            app(CentralSupportOutboundTransport::class)->sendOne());
        $this->assertSame('queued',DB::table('central_support_outbox')
            ->where('id',$id)->value('delivery_state'));
        $this->assertDatabaseCount('central_license_check_state',0);
        $this->assertDatabaseCount('finance_journals',0);
    }
}
