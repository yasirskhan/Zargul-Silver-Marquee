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
        $this->assertCount(2, $state['data']['halls']);

        $state = $service->complete($owner, $company->id, $state['revision']);
        $this->assertSame('completed', $state['status']);
        $this->assertNotNull($state['completedAt']);
        $this->assertSame('Zargul Silver Marquee', DB::table('companies')->value('name'));
        $this->assertSame(2, DB::table('halls')->where('company_id', $company->id)->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('action', 'installation.wizard_completed')->count());

        try {
            $service->complete($owner, $company->id, $state['revision']);
            $this->fail('Completed wizard was reopened.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
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
                'address' => '', 'currency' => 'pkr', 'timezone' => 'Asia/Karachi',
            ]], $state['revision']);
            $this->fail('Lowercase currency accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, Hall::where('company_id', $company->id)->count());
    }
}
