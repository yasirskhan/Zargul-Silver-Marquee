<?php

namespace Tests\Feature;

use App\Filament\Pages\Licensing;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralInstallationIdentity;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CentralLicensingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_bound_owner_only_sees_private_read_only_license_overview(): void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Licensed Hall A','slug'=>'licensed-a']);
        $foreign=Company::create(['name'=>'Foreign Hall B','slug'=>'licensed-b']);
        $owner=User::create(['name'=>'Owner A','email'=>'license-owner@example.test',
            'password'=>'test-only']);
        $owner->markEmailAsVerified();
        $other=User::create(['name'=>'Owner B','email'=>'foreign-owner@example.test',
            'password'=>'test-only']);
        $other->markEmailAsVerified();
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        CompanyMembership::create([
            'company_id'=>$foreign->id,'user_id'=>$other->id,'role'=>'owner']);
        $installation=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$installation['installation_id'],
            'customer_id'=>(string)Str::uuid(),'approved_domain'=>'hall-a.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        Livewire::test(Licensing::class)
            ->assertSet('companyId',$company->id)
            ->assertSet('summary.state','awaiting_signed_license')
            ->assertSee('License status')
            ->assertSee('Last authenticated HAFZATECH verification (UTC)')
            ->assertSee('Never verified online')
            ->assertSee('Due now')
            ->assertSee('Online renewal requests are not yet enabled.')
            ->assertSee('Verify License (central service not activated)')
            ->assertDontSee($foreign->name)
            ->assertSet('summary.revision',null);
        $this->be($other);
        $this->assertFalse(Licensing::canAccess());
        Livewire::test(Licensing::class)->assertForbidden();
        $this->assertDatabaseCount('finance_journals',0);
    }

    public function test_explicit_owner_verify_observes_manual_release_gate_and_shared_cooldown(): void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Manual UX Hall','slug'=>'manual-ux-hall']);
        $owner=User::create(['name'=>'Manual UX Owner',
            'email'=>'manual-ux-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $installation=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$installation['installation_id'],
            'customer_id'=>(string)Str::uuid(),'approved_domain'=>'manual-ux.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        Http::preventStrayRequests();
        Livewire::test(Licensing::class)->call('verifyLicense')->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('central_license_check_state',0);
        config()->set('central_control.manual_checks_enabled',true);
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status'=>
            function ($request) use ($installation){
                return Http::response([
                    'api_version'=>'v1',
                    'registered_installation_id'=>$installation['installation_id'],
                    'nonce'=>$request['nonce'],'revision'=>0,
                    'status'=>'registered_pending_license',
                ],200);
            },
        ]);
        $view=Livewire::test(Licensing::class);
        $view->assertSee('Verify License')->call('verifyLicense')
            ->assertSet('verificationMessage','The central service has not delivered a valid signed license.');
        $view->call('verifyLicense')
            ->assertSet('verificationMessage','A verification was just requested. Please try again shortly.');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('central_manual_license_check_attempts',1);
        $this->assertDatabaseCount('central_signed_license_cache',0);
        $this->assertDatabaseCount('finance_journals',0);
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$owner->id)->update(['role'=>'manager']);
        $view->call('verifyLicense')->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_owner_requests_idempotent_six_month_license_review_without_payment_or_network(): void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Renewal Contact Venue','slug'=>'renewal-contact-venue']);
        $owner=User::create(['name'=>'Review Owner',
            'email'=>'review-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$owner->id,'role'=>'owner']);
        $installation=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$installation['installation_id'],
            'customer_id'=>(string)Str::uuid(),'approved_domain'=>'review.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        Http::preventStrayRequests();
        $page=Livewire::test(Licensing::class);
        $page->call('requestRenewalReview')->assertForbidden();
        $this->assertDatabaseCount('central_support_outbox',0);
        config()->set('central_control.support_enabled',true);
        $page=Livewire::test(Licensing::class)
            ->assertSee('Send license review request')
            ->set('requestedTermMonths','6')
            ->call('requestRenewalReview')
            ->assertSet('renewalReviewMessage',
                'License review request recorded in the private support queue. No payment or renewal has been approved.');
        $this->assertDatabaseCount('central_support_outbox',1);
        $saved=DB::table('central_support_outbox')->first();
        $this->assertSame($company->id,$saved->company_id);
        $this->assertSame($installation['installation_id'],$saved->installation_id);
        $this->assertSame($owner->id,(int)$saved->created_by);
        $plaintext=\Illuminate\Support\Facades\Crypt::decryptString($saved->encrypted_body);
        $this->assertStringContainsString('6-month license',$plaintext);
        $this->assertStringContainsString('not_yet_issued',$plaintext);
        $this->assertStringContainsString('does not confirm payment',$plaintext);
        $this->assertStringNotContainsString($plaintext,$saved->encrypted_body);
        // Repeat browser submit, including a new mounted session, remains
        // the SAME deterministic support request, not a second invoice.
        $page->call('requestRenewalReview');
        Livewire::test(Licensing::class)->set('requestedTermMonths','6')
            ->call('requestRenewalReview');
        $this->assertDatabaseCount('central_support_outbox',1);
        $page->set('requestedTermMonths','18')
            ->call('requestRenewalReview')->assertStatus(422);
        $this->assertDatabaseCount('central_support_outbox',1);
        // Mount and set the selected term while the owner is authorized.
        // After revocation, call the protected action directly: issuing a
        // separate wire:set first destroys the revoked Livewire component
        // and produces 404 instead of exercising the intended 403 gate.
        $revokedPage=Livewire::test(Licensing::class)
            ->set('requestedTermMonths','12');
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$owner->id)->update(['role'=>'manager']);
        $revokedPage->call('requestRenewalReview')->assertForbidden();
        $this->assertDatabaseCount('central_support_outbox',1);
        $this->assertDatabaseCount('finance_journals',0);
        $this->assertDatabaseCount('central_signed_license_cache',0);
        Http::assertNothingSent();
    }

    public function test_owner_verify_downloads_signed_six_month_grant_and_future_paid_renewal(): void
    {
        config()->set('central_control.activation_enabled',true);
        config()->set('central_control.manual_checks_enabled',true);
        $company=Company::create(['name'=>'Signed Renewal Venue','slug'=>'signed-renewal-venue']);
        $owner=User::create(['name'=>'Signed Renewal Owner',
            'email'=>'signed-renewal-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,
            'user_id'=>$owner->id,'role'=>'owner']);
        $installation=app(CentralInstallationIdentity::class)->initialize();
        $customer=(string)Str::uuid();
        $license=(string)Str::uuid();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$installation['installation_id'],
            'customer_id'=>$customer,'approved_domain'=>'signed-renewal.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        // Real operator signing custody is OFF. The HTTP fixture below
        // simulates an independently paid, already approved vendor revision
        // using disposable test-only Ed25519 private material.
        $keys=sodium_crypto_sign_keypair();
        $encode=static fn(string $raw):string=>
            rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
        config()->set('central_control.trusted_signing_public_keys',[
            'synthetic-paid-renewal'=>$encode(sodium_crypto_sign_publickey($keys)),
        ]);
        $sign=static function(array $payload,string $purpose)use($keys,$encode):array {
            $raw=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            return ['key_id'=>'synthetic-paid-renewal','payload'=>$encode($raw),
                'signature'=>$encode(sodium_crypto_sign_detached(
                    $purpose."\\n".$raw,sodium_crypto_sign_secretkey($keys)))];
        };
        $version=1;
        $lastRequestTime=null;
        Http::fake(['https://lichall.hafzatech.com/api/v1/installations/status'=>
            function($request)use(&$version,&$lastRequestTime,$installation,
                $customer,$license,$sign) {
                $lastRequestTime=$request['request_time'];
                $revision=$version==='tampered'?3:$version;
                $grant=[
                    'api_version'=>'v1','issuer'=>'HAFZATECH','product'=>'VenueDesk',
                    'license_id'=>$license,'installation_id'=>$installation['installation_id'],
                    'customer_id'=>$customer,'revision'=>$revision,
                    'term_months'=>6,'edition'=>'standard',
                    'approved_domains'=>['signed-renewal.example.test'],
                    'not_before'=>$revision===1
                        ? '2026-10-01T00:00:00Z' : '2027-04-01T00:00:00Z',
                    'expires_at'=>$revision===1
                        ? '2027-04-01T00:00:00Z' : '2027-10-01T00:00:00Z',
                    'limits'=>['max_halls'=>2,'max_staff'=>10],
                    'features'=>['booking'=>true],
                ];
                $receipt=[
                    'api_version'=>'v1','issuer'=>'HAFZATECH',
                    'installation_id'=>$installation['installation_id'],
                    'customer_id'=>$customer,'nonce'=>$request['nonce'],
                    'status'=>'signed_revision_pending_delivery',
                    'revision'=>$revision,'issued_at'=>$request['request_time'],
                ];
                $signedGrant=$sign($grant,'HAFZATECH-LICENSE-GRANT-V1');
                if($version==='tampered') {
                    $signedGrant['signature']=rtrim(strtr(
                        base64_encode(random_bytes(64)),'+/','-_'),'=');
                }
                return Http::response([
                    'api_version'=>'v1',
                    'registered_installation_id'=>$installation['installation_id'],
                    'nonce'=>$request['nonce'],
                    'status'=>'signed_revision_pending_delivery',
                    'revision'=>$revision,
                    'signed_receipt'=>$sign($receipt,'HAFZATECH-INSTALL-STATUS-RECEIPT-V1'),
                    'signed_grant'=>$signedGrant,
                ],200);
            },
        ]);
        $view=Livewire::test(Licensing::class)
            ->assertSet('summary.state','awaiting_signed_license');
        $view->call('verifyLicense')
            ->assertSet('verificationMessage',
                'License signature and HAFZATECH online status verified.')
            ->assertSet('summary.state','licensed')
            ->assertSet('summary.revision',1)
            ->assertSet('summary.expires_at','2027-04-01T00:00:00Z')
            ->assertSet('summary.last_online_verified_at',$lastRequestTime);
        $this->assertDatabaseCount('central_signed_license_cache',1);
        $this->assertDatabaseCount('central_manual_license_check_attempts',1);
        // Manual on-demand verification has its own one-minute cooldown;
        // simulate its expiration without weakening production throttling.
        DB::table('central_manual_license_check_attempts')
            ->update(['requested_at'=>now()->subMinutes(2)]);
        $version=2;
        $view->call('verifyLicense')
            ->assertSet('verificationMessage',
                'License signature and HAFZATECH online status verified.')
            ->assertSet('summary.state','licensed')
            ->assertSet('summary.revision',1)
            ->assertSet('summary.expires_at','2027-04-01T00:00:00Z')
            ->assertSet('summary.renewal_revision',2)
            ->assertSet('summary.renewal_starts_at','2027-04-01T00:00:00Z')
            ->assertSet('summary.renewal_expires_at','2027-10-01T00:00:00Z')
            ->assertSet('summary.last_online_verified_at',$lastRequestTime);
        $this->assertSame(2,(int)DB::table('central_signed_license_cache')->value('revision'));
        $this->assertSame(1,(int)DB::table('central_signed_license_cache')->value('previous_revision'));
        $lastVerified=DB::table('central_license_check_state')->value('last_online_verified_at');
        DB::table('central_manual_license_check_attempts')
            ->update(['requested_at'=>now()->subMinutes(2)]);
        $version='tampered';
        $view->call('verifyLicense')
            ->assertSet('verificationMessage',
                'Unable to verify online now. Your locally signed license remains unchanged.')
            ->assertSet('summary.renewal_revision',2)
            ->assertSet('summary.renewal_expires_at','2027-10-01T00:00:00Z');
        $this->assertSame($lastVerified,
            DB::table('central_license_check_state')->value('last_online_verified_at'));
        $this->assertSame(2,(int)DB::table('central_signed_license_cache')->value('revision'));
        $this->assertDatabaseCount('central_signed_license_cache',1);
        $this->assertDatabaseCount('finance_journals',0);
        Http::assertSentCount(3);
    }

    public function test_page_rechecks_role_revocation_before_another_livewire_call(): void
    {
        config()->set('central_control.activation_enabled',true);
        $company=Company::create(['name'=>'Authorized Venue','slug'=>'auth-license']);
        $owner=User::create(['name'=>'Owner','email'=>'auth-license-owner@example.test',
            'password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$owner->id,'role'=>'owner']);
        $installation=app(CentralInstallationIdentity::class)->initialize();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,'installation_id'=>$installation['installation_id'],
            'customer_id'=>(string)Str::uuid(),'approved_domain'=>'auth.example.test',
            'status'=>'registered_pending_license','central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        app(CentralInstallationCompanyScope::class)->bind($owner,$company->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->be($owner);
        $view=Livewire::test(Licensing::class)->assertSee('License status');
        DB::table('company_memberships')->where('company_id',$company->id)
            ->where('user_id',$owner->id)->update(['role'=>'manager']);
        $view->call('refreshStatus')->assertForbidden();
    }
}
