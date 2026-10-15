<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffAuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mfa_columns_and_contracts_are_ready_for_filament(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'app_authentication_secret', 'app_authentication_recovery_codes', 'email_verified_at',
        ]));
        $user = User::create([
            'name' => 'Owner', 'email' => 'security@example.test', 'password' => 'test-only',
        ]);
        $this->assertInstanceOf(HasAppAuthentication::class, $user);
        $this->assertInstanceOf(HasAppAuthenticationRecovery::class, $user);
        $this->assertInstanceOf(MustVerifyEmail::class, $user);
        $this->assertFalse($user->hasVerifiedEmail());
        $user->markEmailAsVerified();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_totp_secrets_and_recovery_codes_are_encrypted_and_not_serialized(): void
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'private@example.test', 'password' => 'test-only',
        ]);
        $user->app_authentication_secret = 'TEST-MFA-SECRET-NOT-FOR-USE';
        $user->app_authentication_recovery_codes = ['test-recovery-1', 'test-recovery-2'];
        $user->save();

        $raw = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotSame('TEST-MFA-SECRET-NOT-FOR-USE', $raw->app_authentication_secret);
        $this->assertStringNotContainsString('test-recovery-1', $raw->app_authentication_recovery_codes);
        $fresh = $user->fresh();
        $this->assertSame('TEST-MFA-SECRET-NOT-FOR-USE', $fresh->app_authentication_secret);
        $this->assertSame(['test-recovery-1', 'test-recovery-2'], $fresh->app_authentication_recovery_codes);
        $this->assertArrayNotHasKey('app_authentication_secret', $fresh->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $fresh->toArray());
    }

    public function test_panel_access_requires_active_company_membership(): void
    {
        $company = Company::create(['name' => 'Venue', 'slug' => 'venue']);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'venue@example.test', 'password' => 'test-only',
        ]);
        CompanyMembership::create([
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $panel = Panel::make()->id('admin');
        $this->assertTrue($owner->canAccessPanel($panel));
        $company->update(['is_active' => false]);
        $this->assertFalse($owner->canAccessPanel($panel));
    }
}
