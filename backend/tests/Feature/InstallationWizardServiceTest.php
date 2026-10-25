<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\InstallationWizardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class InstallationWizardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Unconfigured Venue', 'slug' => 'wizard-venue']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'wizard-owner@example.test', 'password' => 'test-only',
        ]);
        $owner->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);

        return [$company, $owner];
    }

    public function test_owner_saves_resumes_and_completes_wizard_without_reset(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(InstallationWizardService::class);
        $state = $service->state($owner, $company->id);
        $this->assertSame('draft', $state['status']);

        $state = $service->save($owner, $company->id, 1, ['company' => [
            'legalName' => 'Zargul Silver Marquee', 'contactEmail' => 'venue@example.test',
            'contactPhone' => '+1 555 0100', 'address' => '1 Wedding Road',
            'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
        ]], $state['revision']);
        $this->assertSame(2, $state['step']);
        $this->assertSame('Zargul Silver Marquee', $state['data']['company']['legalName']);

        $state = $service->save($owner, $company->id, 2, ['halls' => [
            ['id' => null, 'name' => 'Grand Hall', 'address' => 'Hall address',
                'phone' => '+1 555 0101', 'capacity' => 300],
            ['id' => null, 'name' => 'Garden Hall', 'address' => 'Garden address',
                'phone' => '', 'capacity' => 150],
        ]], $state['revision']);
        $this->assertSame(3, $state['step']);
        $this->assertSame('in_progress', $state['status']);
        $this->assertContains('company', $state['data']['completedStages']);
        $this->assertContains('halls', $state['data']['completedStages']);
        $this->assertCount(2, $state['data']['halls']);
        $this->assertSame('Zargul Silver Marquee', DB::table('companies')->value('legal_name'));
        $this->assertSame('PKR', DB::table('companies')->value('currency_code'));
        $this->assertSame(2, DB::table('halls')->where('company_id', $company->id)->count());
        $this->assertSame('Hall address', DB::table('halls')->where('name', 'Grand Hall')->value('address'));

        try {
            $service->complete($owner, $company->id, $state['revision']);
            $this->fail('Partial three-step installation was falsely completed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Complete all approved setup stages', $exception->getMessage());
        }
        $this->assertSame('in_progress', DB::table('installation_wizard_states')->value('status'));
        $this->assertNull(DB::table('installation_wizard_states')->value('completed_at'));
    }

    public function test_forged_saved_license_state_cannot_complete_without_live_signed_grant(): void
    {
        [$company, $owner] = $this->fixture();
        $this->be($owner);
        DB::table('installation_wizard_states')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $company->id,
            'step' => 10, 'revision' => 4, 'status' => 'in_progress',
            'data' => json_encode([
                'company' => ['legalName' => 'Forged License Venue', 'contactEmail' => '',
                    'contactPhone' => '', 'address' => '', 'currency' => 'PKR',
                    'timezone' => 'Asia/Karachi'],
                'halls' => [['id' => null, 'name' => 'Forged Hall',
                    'address' => '', 'phone' => '', 'capacity' => 100]],
                'completedStages' => ['company', 'halls', 'programs', 'pricing',
                    'addons', 'branding', 'payments', 'staff', 'license'],
                'license' => ['state' => 'licensed'],
                'staff' => ['ownerOnly' => true],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            app(InstallationWizardService::class)->complete($owner, $company->id, 4);
            $this->fail('Forged saved license state completed the installation.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('verified signed license', $exception->getMessage());
        }
        $this->assertSame('in_progress', DB::table('installation_wizard_states')->value('status'));
        $this->assertSame(0, DB::table('halls')->where('company_id', $company->id)->count());
    }
    public function test_current_signed_test_grant_allows_successful_audited_completion(): void
    {
        [$company, $owner] = $this->fixture();
        $this->be($owner);
        config()->set('central_control.activation_enabled', true);
        $identity = app(\App\Services\CentralInstallationIdentity::class)->initialize();
        $customer = (string) Str::uuid();
        $license = (string) Str::uuid();
        $domain = 'wizard-complete.example.test';
        DB::table('central_installation_activation')->insert([
            'singleton_id' => 1, 'company_id' => $company->id,
            'installation_id' => $identity['installation_id'],
            'customer_id' => $customer, 'approved_domain' => $domain,
            'status' => 'registered_pending_license', 'central_registered_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pair = sodium_crypto_sign_keypair();
        $b64 = fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        config()->set('central_control.trusted_signing_public_keys', [
            'test-key' => $b64(sodium_crypto_sign_publickey($pair)),
        ]);
        $grant = [
            'api_version' => 'v1', 'issuer' => 'HAFZATECH', 'product' => 'VenueDesk',
            'license_id' => $license, 'installation_id' => $identity['installation_id'],
            'customer_id' => $customer, 'revision' => 1, 'term_months' => 12,
            'edition' => 'standard', 'approved_domains' => [$domain],
            'not_before' => now()->subDay()->toIso8601ZuluString(),
            'expires_at' => now()->addYear()->toIso8601ZuluString(),
            'limits' => ['max_halls' => 2, 'max_staff' => 10],
            'features' => ['booking' => true],
        ];
        $raw = json_encode($grant, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $envelope = [
            'key_id' => 'test-key', 'payload' => $b64($raw),
            'signature' => $b64(sodium_crypto_sign_detached(
                "HAFZATECH-LICENSE-GRANT-V1\n".$raw,
                sodium_crypto_sign_secretkey($pair))),
        ];
        app(\App\Services\CentralSignedLicenseCache::class)->accept(
            $envelope, $customer, $domain, now()->toDateTimeImmutable());
        DB::table('installation_wizard_states')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id,
            'step' => 10, 'revision' => 6, 'status' => 'in_progress',
            'data' => json_encode([
                'company' => ['legalName' => 'Licensed Wizard Venue', 'contactEmail' => '',
                    'contactPhone' => '', 'address' => '', 'currency' => 'PKR',
                    'timezone' => 'Asia/Karachi'],
                'halls' => [['id' => null, 'name' => 'Licensed Hall',
                    'address' => '', 'phone' => '', 'capacity' => 100]],
                'completedStages' => ['company', 'halls', 'programs', 'pricing',
                    'addons', 'branding', 'payments', 'staff', 'license'],
                'license' => ['state' => 'forged-old-string'],
                'staff' => ['ownerOnly' => true],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $state = app(InstallationWizardService::class)->complete($owner, $company->id, 6);
        $this->assertSame('completed', $state['status']);
        $this->assertSame(1, DB::table('halls')->where('company_id', $company->id)->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'installation.wizard_completed')->count());
    }
    public function test_program_pricing_and_addon_stages_persist_through_canonical_services(): void
    {
        [$company, $owner] = $this->fixture();
        $this->be($owner);
        $service = app(InstallationWizardService::class);
        $state = $service->save($owner, $company->id, 1, ['company' => [
            'legalName' => 'Operational Venue', 'contactEmail' => 'ops@example.test',
            'contactPhone' => '', 'address' => '', 'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ]], $service->state($owner, $company->id)['revision']);
        $state = $service->save($owner, $company->id, 2, ['halls' => [[
            'id' => null, 'name' => 'Operational Hall', 'address' => 'Address',
            'phone' => '', 'capacity' => 220,
        ]]], $state['revision']);
        $hallId = $state['data']['halls'][0]['id'];
        $slot = static fn (string $start, string $end): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 20,
            'cleaningMinutes' => 20, 'graceMinutes' => 15,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 5000,
            'rentalRateMinor' => 150000,
        ];
        $state = $service->savePrograms($owner, $company->id, [
            $hallId => [
                'morning' => $slot('08:00', '11:00'),
                'noon' => $slot('12:00', '15:00'),
                'evening' => $slot('17:00', '22:00'),
            ],
        ], $state['revision']);
        $this->assertContains('programs', $state['data']['completedStages']);
        $this->assertSame(1, (int) DB::table('halls')->where('id', $hallId)
            ->value('program_rules_revision'));

        $state = $service->savePricing($owner, $company->id, [
            $hallId => ['capacity' => 220, 'includedChairs' => 200,
                'includedTables' => 20, 'extraChairRateMinor' => 500,
                'extraTableRateMinor' => 2000],
        ], $state['revision']);
        $this->assertContains('pricing', $state['data']['completedStages']);
        $this->assertSame(1, (int) DB::table('halls')->where('id', $hallId)
            ->value('seating_price_revision'));

        $state = $service->saveAddons($owner, $company->id, [
            $hallId => [[
                'code' => 'stage_lighting', 'name' => 'Stage lighting',
                'category' => 'lighting', 'chargeUnit' => 'flat',
                'rateMinor' => 25000, 'enabled' => true,
            ]],
        ], $state['revision']);
        $this->assertContains('addons', $state['data']['completedStages']);
        $this->assertSame(1, (int) DB::table('halls')->where('id', $hallId)
            ->value('addon_catalog_revision'));

        $state = $service->saveBranding($owner, $company->id, [
            'siteName' => 'Operational Venue Website', 'tagline' => 'Weddings in the marquee',
            'themePreset' => 'ivory', 'fontPreset' => 'modern',
            'footerText' => 'Synthetic footer', 'seoDescription' => 'Synthetic description',
        ], $state['revision']);
        $this->assertContains('branding', $state['data']['completedStages']);
        $this->assertSame(1, DB::table('websites')->where('company_id', $company->id)->count());
        $this->assertSame(1, DB::table('site_branding_revisions')
            ->where('company_id', $company->id)->count());
        $this->assertSame('in_progress', DB::table('installation_wizard_states')->value('status'));
        $this->assertSame(3, DB::table('audit_events')
            ->whereIn('action', ['hall.program_rules_updated',
                'hall.seating_prices_updated', 'hall.addon_catalog_updated'])->count());
        $this->assertSame('in_progress', DB::table('installation_wizard_states')->value('status'));
    }

    public function test_inactive_hall_is_not_reactivated_or_deleted_by_setup(): void
    {
        [$company, $owner] = $this->fixture();
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Closed Hall',
            'slug' => 'closed-hall', 'capacity' => 80, 'is_active' => false,
        ]);
        $service = app(InstallationWizardService::class);
        $state = $service->save($owner, $company->id, 2, ['halls' => [[
            'id' => $hall->id, 'name' => 'Renamed Closed Hall',
            'address' => 'New address', 'phone' => '+1 555 0199', 'capacity' => 90,
        ]]], $service->state($owner, $company->id)['revision']);
        $this->assertSame('Renamed Closed Hall', DB::table('halls')->where('id', $hall->id)->value('name'));
        $this->assertSame(90, (int) DB::table('halls')->where('id', $hall->id)->value('capacity'));
        $this->assertSame('New address', DB::table('halls')->where('id', $hall->id)->value('address'));
        $this->assertFalse((bool) DB::table('halls')->where('id', $hall->id)->value('is_active'));
        $this->assertSame(1, DB::table('halls')->where('company_id', $company->id)->count());
        $this->assertNotNull($state['data']['halls'][0]['id']);
    }

    public function test_stale_revision_foreign_manager_and_invalid_currency_fail_closed(): void
    {
        [$company, $owner] = $this->fixture();
        $service = app(InstallationWizardService::class);
        $state = $service->state($owner, $company->id);
        $service->save($owner, $company->id, 1, ['company' => [
            'legalName' => 'First Name', 'contactEmail' => '', 'contactPhone' => '',
            'address' => '', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
        ]], $state['revision']);

        try {
            $service->save($owner, $company->id, 1, ['company' => [
                'legalName' => 'Stale Name', 'contactEmail' => '', 'contactPhone' => '',
                'address' => '', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi',
            ]], $state['revision']);
            $this->fail('Stale wizard revision saved.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }

        $manager = User::create([
            'name' => 'Manager', 'email' => 'wizard-manager@example.test', 'password' => 'test-only',
        ]);
        $manager->markEmailAsVerified();
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $manager->id, 'role' => 'manager',
        ]);
        try {
            $service->state($manager, $company->id);
            $this->fail('Manager opened owner wizard.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $state = $service->state($owner, $company->id);
        try {
            $service->save($owner, $company->id, 1, ['company' => [
                'legalName' => 'Valid Name', 'contactEmail' => '', 'contactPhone' => '',
                'address' => '', 'currency' => 'PK', 'timezone' => 'Asia/Karachi',
            ]], $state['revision']);
            $this->fail('Lowercase currency accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, Hall::where('company_id', $company->id)->count());
    }
}
