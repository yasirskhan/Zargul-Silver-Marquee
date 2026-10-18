<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicSiteRequestGate;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\TenantAccess;
use App\Services\ApprovalWorkflowService;
use App\Policies\FinanceAccessPolicy;
use App\Policies\OperationsPlanningPolicy;
use App\Services\InternalQuickSearchService;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\CentralInstallationIdentity;
use App\Services\CentralInstallationCompanyScope;
use App\Services\CentralSignedLicenseCache;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CentralPublicLicenseReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function assertBlocked(): void
    {
        try {
            app(PublicSiteRequestGate::class)->authorize(
                Request::create('https://licensed.example.test/')
            );
            $this->fail('Missing or expired signed license opened a public website.');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    public function test_default_off_does_not_restrict_public_host_and_signed_expiry_blocks_all_public_paths(): void
    {
        $company=Company::create([
            'name'=>'License Gate Venue', 'slug'=>'license-gate-venue'
        ]);
        $owner=User::create(['name'=>'Signed-License Owner',
            'email'=>'signed-license-owner@example.test','password'=>'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id'=>$company->id, 'user_id'=>$owner->id, 'role'=>'owner']);
        $hall=Hall::create([
            'company_id'=>$company->id,'name'=>'License Restricted Hall',
            'slug'=>'restricted-license-hall','capacity'=>200
        ]);
        $manager=User::create([
            'name'=>'Restricted Venue Manager',
            'email'=>'restricted-license-manager@example.test','password'=>'test-only']);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id'=>$company->id,'user_id'=>$manager->id,'role'=>'manager'
        ]);
        $tenant=app(TenantAccess::class);
        $this->assertTrue($tenant->canReadAuditHistory($owner,$company->id),
            'Verified owner historical audit is available when licensing is OFF');
        $this->assertTrue($tenant->canCorrectExistingApprovedRefund($owner,$hall),
            'Verified owner existing-payment refund correction is permitted before release');
        $this->assertFalse($tenant->canReadAuditHistory($manager,$company->id));
        $this->assertFalse($tenant->canCorrectExistingApprovedRefund($manager,$hall));
        $this->assertTrue($tenant->canManageCustomerProfiles($owner,$company->id),
            'Default-OFF licensing preserves management permissions');
        $this->be($owner);
        $planning=app(OperationsPlanningPolicy::class);
        $search=app(InternalQuickSearchService::class);
        $this->assertNotNull($planning->membership($owner,$company->id),
            'Unreleased licensing leaves operations planning membership unchanged');
        $this->assertSame([], $search->search($owner,$company->id,'missing-fixture'),
            'Unreleased licensing leaves private search available');
        $finance=app(FinanceAccessPolicy::class);
        $this->assertTrue($finance->view($owner,$company->id),
            'Default-OFF licensing preserves owner finance access');

        $website=(string)Str::uuid();
        DB::table('websites')->insert([
            'id'=>$website,'company_id'=>$company->id,'name'=>'Synthetic public site'
        ]);
        config([
            'public_site.enabled'=>true,
            'public_site.host'=>'licensed.example.test',
            'public_site.company_id'=>$company->id,
            'public_site.website_id'=>$website,
            'public_site.require_verified_domain'=>false,
            'central_control.activation_enabled'=>false,
        ]);
        $request=Request::create('https://licensed.example.test/');
        $this->assertSame('licensed.example.test',
            app(PublicSiteRequestGate::class)->authorize($request)['host']);

        config()->set('central_control.activation_enabled',true);
        $this->assertBlocked();
        $this->assertFalse($tenant->canManageCustomerProfiles($owner,$company->id),
            'Commercial release cannot authorize company before signed enrollment');
        $this->assertFalse($tenant->canReadAuditHistory($owner,$company->id),
            'Unbound company has no restricted historical bypass');
        $this->assertNull($planning->membership($owner,$company->id));
        try {
            $search->search($owner,$company->id,'missing-fixture');
            $this->fail('Unsigned private record search was allowed.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $identity=app(CentralInstallationIdentity::class)->initialize();
        $customer=(string)Str::uuid();
        DB::table('central_installation_activation')->insert([
            'singleton_id'=>1,
            'installation_id'=>$identity['installation_id'],
            'customer_id'=>$customer,
            'company_id'=>$company->id,
            'approved_domain'=>'licensed.example.test',
            'status'=>'registered_pending_license',
            'central_registered_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->assertBlocked();
        $this->assertFalse($tenant->canManageCustomerProfiles($owner,$company->id),
            'Enrolled but unsigned company remains restricted');
        $this->assertTrue($tenant->canReadAuditHistory($owner,$company->id),
            'Bound owner retains rights to existing historical records');
        $this->assertTrue($tenant->canCorrectExistingApprovedRefund($owner,$hall),
            'Bound owner may reconcile an independently preapproved existing refund');
        $pair=sodium_crypto_sign_keypair();
        config()->set('central_control.trusted_signing_public_keys',[
            'fixture-test-key'=>self::b64(sodium_crypto_sign_publickey($pair))
        ]);
        $grant=[
            'api_version'=>'v1','issuer'=>'HAFZATECH','product'=>'VenueDesk',
            'license_id'=>(string)Str::uuid(),
            'installation_id'=>$identity['installation_id'],
            'customer_id'=>$customer,
            'revision'=>1,'term_months'=>12,'edition'=>'standard',
            'approved_domains'=>['licensed.example.test'],
            'not_before'=>'2026-10-01T00:00:00Z',
            'expires_at'=>'2027-10-01T00:00:00Z',
            'limits'=>['max_halls'=>2,'max_staff'=>10],
            'features'=>['booking'=>true],
        ];
        $raw=json_encode($grant, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $envelope=[
            'key_id'=>'fixture-test-key',
            'payload'=>self::b64($raw),
            'signature'=>self::b64(sodium_crypto_sign_detached(
                'HAFZATECH-LICENSE-GRANT-V1'."\n".$raw,
                sodium_crypto_sign_secretkey($pair)
            )),
        ];
        app(CentralSignedLicenseCache::class)->accept(
            $envelope,$customer,'licensed.example.test',
            new DateTimeImmutable('2026-10-18T12:00:00Z')
        );
        try {
            // No successful online check is recorded; a missed 15-day
            // network check alone MUST NOT shut down a still-valid grant.
            $this->travelTo(Carbon::parse('2027-09-16T00:00:00Z'));
            $this->assertSame('licensed.example.test',
                app(PublicSiteRequestGate::class)->authorize($request)['host']);

            $this->assertTrue($tenant->canManageCustomerProfiles($owner,$company->id),
                'Active vendor-signed license permits company operations');
            $this->assertTrue($finance->view($owner,$company->id),
                'Active vendor-signed license permits scoped finance');
            $this->assertNotNull($planning->membership($owner,$company->id),
                'Signed license permits scoped operations planning');
            $this->assertSame([], $search->search($owner,$company->id,'missing-fixture'));
            config()->set('public_site.company_id',(string)Str::uuid());
            $this->assertBlocked();
            config()->set('public_site.company_id',$company->id);

            // An operator host alone must not override the single immutable
            // registered domain or the installer-pinned signature trust key.
            DB::table('central_installation_activation')
                ->where('singleton_id',1)->update(['approved_domain'=>'other.example.test']);
            $this->assertBlocked();
            $this->assertFalse($tenant->canManageCustomerProfiles($owner,$company->id),
                'Wrong approved license domain also denies tenant business access');
            DB::table('central_installation_activation')
                ->where('singleton_id',1)->update(['approved_domain'=>'licensed.example.test']);
            $this->assertSame('licensed.example.test',
                app(PublicSiteRequestGate::class)->authorize($request)['host']);

            config()->set('central_control.trusted_signing_public_keys',[]);
            $this->assertBlocked();
            $this->assertFalse($tenant->canManageCustomerProfiles($owner,$company->id),
                'Untrusted signer prevents management authorization');
            config()->set('central_control.trusted_signing_public_keys',[
                'fixture-test-key'=>self::b64(sodium_crypto_sign_publickey($pair))
            ]);
            $this->assertSame('licensed.example.test',
                app(PublicSiteRequestGate::class)->authorize($request)['host']);

            $this->travelTo(Carbon::parse('2027-10-01T00:00:00Z'));
            $this->assertBlocked();
            $this->assertTrue($tenant->canManageCustomerProfiles($owner,$company->id),
                'Business operations remain available at signed expiry during grace');
            foreach (['/', '/robots.txt', '/sitemap.xml',
                '/_venue-media/home/'.Str::uuid().'.webp'] as $path) {
                $this->get('https://licensed.example.test'.$path)
                    ->assertStatus(503);
            }
            $this->travelTo(Carbon::parse('2027-10-07T23:59:59Z'));
            $this->assertTrue($tenant->canManageCustomerProfiles($owner,$company->id),
                'Seven-day operations grace includes the last second');
            $this->travelTo(Carbon::parse('2027-10-08T00:00:00Z'));
            $this->assertFalse($tenant->canManageCustomerProfiles($owner,$company->id),
                'Normal operations stop precisely at signed expiry plus seven days');
            $this->assertFalse($finance->view($owner,$company->id),
                'Expired grace cannot bypass finance authorization');
            $this->assertFalse($tenant->canManageBookings($owner,$hall),
                'Business operations remain restricted post-grace');
            $this->assertTrue($tenant->canReadAuditHistory($owner,$company->id),
                'Owner must retain read-only historical audits after expiry');
            $this->assertTrue($tenant->canCorrectExistingApprovedRefund($owner,$hall),
                'Existing independently approved full-payment refunds remain correctable');
            $this->assertFalse($tenant->canReadAuditHistory($manager,$company->id));
            $this->assertFalse($tenant->canCorrectExistingApprovedRefund($manager,$hall));
            $this->assertNull($planning->membership($owner,$company->id),
                'Post-grace planning membership is rejected');
            try {
                $search->search($owner,$company->id,'missing-fixture');
                $this->fail('Expired license allowed private record search.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
            $this->be($owner);
            try {
                app(ApprovalWorkflowService::class)->request(
                    $owner,$company->id,(string)Str::uuid(),'expense',1,'PKR',
                    null,'Synthetic request after commercial grace expiry');
                $this->fail('Expired license unexpectedly allowed financial approval request');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
            // Licensing, verified owner company scope and HAFZATECH support
            // remain available to resolve renewal even after operations end.
            app(CentralInstallationCompanyScope::class)->assertCanRead($owner,$company->id);

            $this->assertSame('registered_pending_license',
                DB::table('central_installation_activation')
                    ->where('singleton_id',1)->value('status'));
            $this->assertDatabaseCount('finance_journals',0);
        } finally {
            Carbon::setTestNow();
        }
    }
}
