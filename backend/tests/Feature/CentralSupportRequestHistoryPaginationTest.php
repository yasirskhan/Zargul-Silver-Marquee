<?php

namespace Tests\Feature;

use App\Filament\Pages\SupportInbox;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralSupportOutboxReader;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class CentralSupportRequestHistoryPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_read_older_local_outbox_without_support_delivery_release():void
    {
        config()->set('central_control.activation_enabled',true);
        config()->set('central_control.support_enabled',false);
        $company=Company::create(['name'=>'Support History Venue','slug'=>'support-history']);
        $owner=User::create([
            'name'=>'Support History Owner',
            'email'=>'history-owner@example.test',
            'password'=>'synthetic-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner',
        ]);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'company_id'=>null,
            'installation_id'=>$identity['installation_id'],
            'customer_id'=>(string)Str::uuid(),
            'approved_domain'=>'history.example.test',
            'status'=>'registered_pending_license',
            'central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        $rows=[];
        $base=now()->subHour();
        for($n=1;$n<=45;$n++){
            $created=$base->copy()->addSeconds($n);
            $rows[]=[
                'id'=>(string)Str::uuid(),
                'company_id'=>$company->id,
                'installation_id'=>$identity['installation_id'],
                'created_by'=>$owner->id,
                'client_request_id'=>(string)Str::uuid(),
                'payload_sha256'=>hash('sha256','fixture'.$n),
                'encrypted_subject'=>Crypt::encryptString('Support subject '.$n),
                'encrypted_body'=>Crypt::encryptString('Synthetic support request '.$n),
                'delivery_state'=>'queued',
                'created_at'=>$created,'updated_at'=>$created,
            ];
        }
        DB::table('central_support_outbox')->insert($rows);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $view=Livewire::test(SupportInbox::class)
            ->assertSet('hasOlderRequests',true)
            ->assertSee('Queued locally (not sent)');
        $this->assertCount(20,$view->get('requests'));
        $this->assertSame('Support subject 45',$view->get('requests')[0]['subject']);
        $view->call('loadOlderRequests')->assertSet('hasOlderRequests',true);
        $this->assertCount(20,$view->get('requests'));
        $this->assertSame('Support subject 25',$view->get('requests')[0]['subject']);
        $view->call('loadOlderRequests')->assertSet('hasOlderRequests',false);
        $this->assertCount(5,$view->get('requests'));
        $this->assertSame('Support subject 1',$view->get('requests')[4]['subject']);
        $view->call('showRecentRequests')->assertSet('hasOlderRequests',true);
        $this->assertSame('Support subject 45',$view->get('requests')[0]['subject']);
        $this->assertDatabaseCount('central_license_check_state',0);
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_history_cursor_is_bounded_and_cannot_bypass_ownership():void
    {
        $reader=app(CentralSupportOutboxReader::class);
        $this->expectException(InvalidArgumentException::class);
        $reader->list(new User(),(string)Str::uuid(),20,'not-a-date',(string)Str::uuid());
    }

    public function test_valid_cursor_still_denies_foreign_unenrolled_user():void
    {
        $reader=app(CentralSupportOutboxReader::class);
        $this->expectException(AuthorizationException::class);
        $reader->list(new User(),(string)Str::uuid(),20,
            '2026-10-17 20:00:00+00','74c72851-dccd-4dc4-b9de-16659d7b6ad4');
    }
}
