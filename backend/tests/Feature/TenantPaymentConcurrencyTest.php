<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\TenantPaymentSettingsService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class TenantPaymentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_second_connection_cannot_update_a_locked_payment_revision(): void
    {
        $company = Company::create(['name' => 'Concurrency Venue', 'slug' => 'concurrency-venue']);
        $owner = User::create(['name' => 'Owner', 'email' => 'concurrency@example.test',
            'password' => 'test-only']);
        $owner->markEmailAsVerified();
        CompanyMembership::create(['company_id' => $company->id,
            'user_id' => $owner->id, 'role' => 'owner']);
        app(TenantPaymentSettingsService::class)->save($owner, $company->id, [
            'banks' => [], 'instructions' => 'Initial', 'qrInstructions' => '',
            'methods' => ['cash'], 'depositPercent' => 0, 'providerApiEnabled' => false,
        ], 0);

        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
            $config['host'], $config['port'], $config['database']);
        $one = new PDO($dsn, $config['username'], $config['password']);
        $two = new PDO($dsn, $config['username'], $config['password']);
        $one->beginTransaction();
        $stmt = $one->prepare('SELECT revision FROM tenant_payment_settings WHERE company_id = ? FOR UPDATE');
        $stmt->execute([$company->id]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $two->beginTransaction();
        $blocked = false;
        try {
            $lock = $two->prepare('SELECT revision FROM tenant_payment_settings WHERE company_id = ? FOR UPDATE NOWAIT');
            $lock->execute([$company->id]);
        } catch (PDOException $exception) {
            $blocked = str_contains($exception->getMessage(), 'could not obtain lock')
                || str_contains($exception->getMessage(), 'lock');
        }
        $two->rollBack();
        $one->commit();
        $this->assertTrue($blocked, 'Second connection unexpectedly updated a locked payment revision.');
        $this->assertSame(1, (int) DB::table('tenant_payment_settings')
            ->where('company_id', $company->id)->value('revision'));

        try {
            app(TenantPaymentSettingsService::class)->save($owner, $company->id, [
                'banks' => [], 'instructions' => 'Stale', 'qrInstructions' => '',
                'methods' => ['cash'], 'depositPercent' => 0, 'providerApiEnabled' => false,
            ], 0);
            $this->fail('Stale payment revision was accepted.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, (int) DB::table('tenant_payment_settings')
                ->where('company_id', $company->id)->value('revision'));
        }
    }
}
