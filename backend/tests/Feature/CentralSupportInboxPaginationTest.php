<?php

namespace Tests\Feature;

use App\Filament\Pages\SupportInbox;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralSupportReplyInboxReader;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CentralSupportInboxPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_page_all_local_replies_with_keyset_cursor_and_return_to_recent():void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Paginated Help Venue','slug'=>'paginated-help']);
        $owner=User::create([
            'name'=>'Paginated Owner','email'=>'paginated-owner@example.test',
            'password'=>'fixture-only-password'
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner'
        ]);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'company_id'=>null,'customer_id'=>(string)Str::uuid(),
            'approved_domain'=>'paginated-help.example.test',
            'status'=>'registered_pending_license',
            'central_registered_at'=>now(),'created_at'=>now(),'updated_at'=>now()
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        $now=now();
        $rows=[];
        for($event=1;$event<=55;$event++){
            $rows[]=[
                'id'=>(string)Str::uuid(), 'company_id'=>$company->id,
                'installation_id'=>$identity['installation_id'],
                'vendor_event_id'=>$event,
                'client_ticket_id'=>(string)Str::uuid(),
                'vendor_message_id'=>(string)Str::uuid(),
                'vendor_ticket_number'=>'HAF-00000123',
                'encrypted_body'=>Crypt::encryptString('Support reply '.$event),
                'vendor_occurred_at'=>$now,
                'read_at'=>null,'created_at'=>$now,'updated_at'=>$now,
            ];
        }
        DB::table('central_support_reply_inbox')->insert($rows);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $view=Livewire::test(SupportInbox::class)
            ->assertSet('hasOlderMessages',true)
            ->assertSet('olderBeforeEvent',null);
        $this->assertCount(25,$view->get('messages'));
        $this->assertSame(55,$view->get('messages')[0]['vendor_event_id']);
        $view->call('loadOlderReplies')
            ->assertSet('olderBeforeEvent',31)
            ->assertSet('hasOlderMessages',true);
        $this->assertCount(25,$view->get('messages'));
        $this->assertSame(30,$view->get('messages')[0]['vendor_event_id']);
        $view->call('loadOlderReplies')
            ->assertSet('olderBeforeEvent',6)
            ->assertSet('hasOlderMessages',false);
        $this->assertCount(5,$view->get('messages'));
        $this->assertSame(1,$view->get('messages')[4]['vendor_event_id']);
        $view->call('showRecentReplies')
            ->assertSet('olderBeforeEvent',null)
            ->assertSet('hasOlderMessages',true);
        $this->assertSame(55,$view->get('messages')[0]['vendor_event_id']);
        $view->call('toggleUnreadOnly')
            ->assertSet('unreadOnly',true)
            ->assertSet('olderBeforeEvent',null);
        $this->assertSame(55,$view->get('messages')[0]['vendor_event_id']);
        $unreadMessage=$view->get('messages')[0]['id'];
        $view->call('markMessageRead',$unreadMessage)->assertSet('unread',54);
        $this->assertSame(54,$view->get('messages')[0]['vendor_event_id']);
        $this->assertDatabaseCount('central_support_reply_read_events',1);
        $view->call('toggleUnreadOnly')
            ->assertSet('unreadOnly',false)
            ->assertSet('olderBeforeEvent',null);
        $this->assertSame(55,$view->get('messages')[0]['vendor_event_id']);
        $this->assertSame(54,app(CentralSupportReplyInboxReader::class)->unreadCount($owner,$company->id));
        $this->assertDatabaseCount('central_license_check_state',0);
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_before_cursor_keeps_company_authorization_and_rejects_invalid_cursor():void
    {
        $reader=app(CentralSupportReplyInboxReader::class);
        try {
            $reader->list(new User(),(string)Str::uuid(),25,50);
            $this->fail('Foreign user paged a private vendor inbox.');
        } catch(AuthorizationException $expected) {}
        $this->expectException(\InvalidArgumentException::class);
        $reader->list(new User(),(string)Str::uuid(),25,0);
    }
}
