<?php

namespace Tests\Feature;

use App\Filament\Pages\SupportInbox;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralInstallationCompanyScope;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CentralSupportInboxComposePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_composer_hidden_and_unusable_without_explicit_release():void
    {
        config()->set('central_control.activation_enabled',true);
        config()->set('central_control.support_enabled',false);
        $company=Company::create(['name'=>'Composer Venue','slug'=>'composer-venue']);
        $owner=User::create(['name'=>'Composer Owner','email'=>'composer-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $identity=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$identity['installation_id'],
            'customer_id'=>(string)Str::uuid(),
            'approved_domain'=>'composer.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        Http::preventStrayRequests();
        Livewire::test(SupportInbox::class)
            ->assertDontSee('Queue support request')
            ->set('newSubject','Allowed words')
            ->set('newMessage','A harmless setup help request.')
            ->call('submitTicket')->assertForbidden();
        $this->assertDatabaseCount('central_support_outbox',0);
        Http::assertNothingSent();
    }
}
