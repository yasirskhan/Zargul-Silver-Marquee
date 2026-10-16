<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Hall;
use App\Models\User;
use App\Services\BookingDraftReviewService;
use App\Services\BookingDraftService;
use App\Services\CustomerProfileService;
use App\Services\FinanceControlService;
use App\Services\HallProgramRulesService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class FinanceControlServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['name' => 'Test Venue', 'slug' => 'finance-venue']);
        $other = Company::create(['name' => 'Other Venue', 'slug' => 'finance-other']);
        $hall = Hall::create([
            'company_id' => $company->id, 'name' => 'Grand',
            'slug' => 'grand', 'capacity' => 250,
        ]);
        $people = [];
        foreach (['owner', 'admin', 'manager'] as $role) {
            $person = User::create([
                'name' => ucfirst($role), 'email' => 'finance-'.$role.'@example.test',
                'password' => 'test-only',
            ]);
            $person->markEmailAsVerified();
            CompanyMembership::create([
                'company_id' => $company->id, 'user_id' => $person->id, 'role' => $role,
            ]);
            $people[$role] = $person;
        }
        $this->be($people['owner']);
        $rules = static fn (string $start, string $end, int $price): array => [
            'start' => $start, 'end' => $end, 'setupMinutes' => 10,
            'cleaningMinutes' => 10, 'graceMinutes' => 10,
            'overtimeIntervalMinutes' => 30, 'overtimeRateMinor' => 0,
            'rentalRateMinor' => $price,
        ];
        app(HallProgramRulesService::class)->configure(
            $people['owner'], $company->id, $hall->id, [
                'morning' => $rules('08:00', '11:00', 1200),
                'noon' => $rules('12:00', '15:00', 1500),
                'evening' => $rules('17:00', '22:00', 2000),
            ], 'PKR', 0, 'Synthetic frozen rental rates'
        );
        $customer = (string) Str::uuid();
        app(CustomerProfileService::class)->create(
            $people['owner'], $company->id, $customer, 'Fake Test Contact',
            'synthetic-finance@example.test', null, 'No live clients'
        );
        $booking = (string) Str::uuid();
        app(BookingDraftService::class)->create(
            $people['owner'], $company->id, $booking, $hall->id, $customer,
            '2027-01-02', 'evening', 90, 'Internal unconfirmed proposal'
        );
        app(BookingDraftReviewService::class)->submit(
            $people['owner'], $company->id, $booking, 1, 'Internal review state only'
        );
        return [$company, $other, $hall, $people, $booking];
    }

    public function test_freezes_internal_rental_preview_without_issuing_invoice_or_changing_draft(): void
    {
        [$company, , $hall, $people, $booking] = $this->fixture();
        $service = app(FinanceControlService::class);
        $id = (string) Str::uuid();
        $first = $service->preview($people['owner'], $company->id,
            $id, $booking, 2, 'Keep internal venue rental preview');
        $this->assertSame('internal_nonbinding', $first['status']);
        $this->assertSame(2000, $first['subtotalMinor']);
        $this->assertSame('PKR', $first['currency']);
        $this->assertSame(1, $first['sourceRevision']);

        $again = $service->preview($people['owner'], $company->id,
            $id, $booking, 2, 'Keep internal venue rental preview');
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, DB::table('finance_quote_previews')->count());
        $this->assertSame('under_review', DB::table('booking_drafts')->where('id', $booking)->value('status'));
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertSame(0, DB::table('hall_slot_claims')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'finance.preview_created')->count());
        $this->assertSame(1, DB::table('finance_quote_previews')->where('hall_id', $hall->id)->count());
        $this->assertStringNotContainsString('synthetic-finance@example.test',
            DB::table('finance_quote_previews')->value('frozen_lines'));
    }

    public function test_idempotent_opening_and_one_real_double_entry_reversal(): void
    {
        [$company, , , $people] = $this->fixture();
        config()->set('finance_controls.posting_enabled', true);
        $service = app(FinanceControlService::class);
        $openId = (string) Str::uuid();
        $reversalId = (string) Str::uuid();
        $a = $service->openCash($people['owner'], $company->id,
            $openId, 'PKR', 120000, 'Verified synthetic opening cash count');
        $b = $service->openCash($people['owner'], $company->id,
            $openId, 'PKR', 120000, 'Verified synthetic opening cash count');
        $this->assertSame($a, $b);
        $this->assertSame(120000, $service->balance(
            $people['owner'], $company->id, 'PKR', now('UTC')->toDateString()));
        $this->assertSame(2, DB::table('finance_journal_lines')->where('journal_id', $openId)->count());
        $rev = $service->reverseOpening($people['owner'], $company->id,
            $reversalId, $openId, 'Corrected opening cash miscount');
        $this->assertSame(120000, $rev['amountMinor']);
        $this->assertSame($rev, $service->reverseOpening($people['owner'], $company->id,
            $reversalId, $openId, 'Corrected opening cash miscount'));
        $this->assertSame(0, $service->balance(
            $people['owner'], $company->id, 'PKR', now('UTC')->toDateString()));
        $this->assertSame(2, DB::table('finance_journals')->count());
        $this->assertSame(4, DB::table('finance_journal_lines')->count());
        $this->assertSame(1, DB::table('finance_journals')->where('reversal_of', $openId)->count());
        $this->assertSame(2, DB::table('audit_events')->where('action', 'like', 'finance.%_posted')->count());
    }

    public function test_posting_off_by_default_and_manager_or_admin_cannot_override_owner_post(): void
    {
        [$company, $other, , $people] = $this->fixture();
        $service = app(FinanceControlService::class);
        try {
            $service->openCash($people['owner'], $company->id, (string) Str::uuid(),
                'PKR', 500, 'Should fail closed');
            $this->fail('Live posting enabled without release configuration.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        config()->set('finance_controls.posting_enabled', true);
        $this->be($people['admin']);
        try {
            $service->openCash($people['admin'], $company->id, (string) Str::uuid(),
                'PKR', 500, 'Only owner may post');
            $this->fail('Administrator financial write accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($people['manager']);
        try {
            $service->previews($people['manager'], $company->id);
            $this->fail('Manager saw owner finance data.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->be($people['owner']);
        try {
            $service->balance($people['owner'], $other->id, 'PKR', now('UTC')->toDateString());
            $this->fail('Cross tenant balance access granted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, DB::table('finance_journals')->count());
    }

    public function test_reversal_conflicts_prevent_double_correction_and_cross_company_attack(): void
    {
        [$company, $other, , $people] = $this->fixture();
        config()->set('finance_controls.posting_enabled', true);
        $service = app(FinanceControlService::class);
        $id = (string) Str::uuid();
        $service->openCash($people['owner'], $company->id, $id,
            'PKR', 300, 'Opening synthetic cash');
        $service->reverseOpening($people['owner'], $company->id,
            (string) Str::uuid(), $id, 'First authorized correction');
        try {
            $service->reverseOpening($people['owner'], $company->id,
                (string) Str::uuid(), $id, 'Attempt second correction');
            $this->fail('Twice-reversed journal accepted.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->expectException(AuthorizationException::class);
        $service->reverseOpening($people['owner'], $other->id,
            (string) Str::uuid(), $id, 'Forged company reversal');
    }

    public function test_cash_closeout_records_mismatch_and_blocks_same_day_posting(): void
    {
        [$company, , , $people] = $this->fixture();
        config()->set('finance_controls.posting_enabled', true);
        $service = app(FinanceControlService::class);
        $date = now('UTC')->toDateString();
        $service->openCash($people['owner'], $company->id,
            (string) Str::uuid(), 'PKR', 1200, 'Count verified synthetic float');
        $closeId = (string) Str::uuid();
        $result = $service->closeCash($people['owner'], $company->id,
            $closeId, 'PKR', $date, 1150, 'Discrepancy recorded for review');
        $this->assertSame(-50, $result['varianceMinor']);
        $this->assertSame(1200, $result['expectedMinor']);
        $this->assertSame($result, $service->closeCash($people['owner'], $company->id,
            $closeId, 'PKR', $date, 1150, 'Discrepancy recorded for review'));
        $this->assertSame(1200, $service->balance($people['owner'], $company->id, 'PKR', $date));
        $this->assertSame(1, DB::table('finance_journals')->count());
        $this->expectException(ConflictHttpException::class);
        $service->openCash($people['owner'], $company->id,
            (string) Str::uuid(), 'PKR', 200, 'Forbidden after closeout');
    }

    public function test_postgres_rejects_unbalanced_direct_journal_and_immutable_history(): void
    {
        [$company, , , $people] = $this->fixture();
        config()->set('finance_controls.posting_enabled', true);
        $service = app(FinanceControlService::class);
        $open = (string) Str::uuid();
        $service->openCash($people['owner'], $company->id, $open,
            'PKR', 400, 'Authentic balanced opening');
        try {
            DB::transaction(function () use ($company, $people): void {
                DB::table('finance_journals')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $company->id,
                    'entry_type' => 'opening_cash', 'currency_code' => 'PKR',
                    'amount_minor' => 100, 'reversal_of' => null,
                    'created_by' => $people['owner']->id, 'reason' => 'Illicit incomplete journal',
                ]);
                DB::statement('SET CONSTRAINTS finance_parent_balanced, finance_lines_balanced IMMEDIATE');
            });
            $this->fail('Incomplete direct PostgreSQL journal was accepted.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('exactly two balanced lines', $error->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('finance_journal_lines')->where('journal_id', $open)
                ->update(['amount_minor' => 3]));
            $this->fail('Immutable journal line was edited.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('history entries cannot be updated or deleted', $error->getMessage());
        }
        $this->assertSame(1, DB::table('finance_journals')->count());
        $this->assertSame(2, DB::table('finance_journal_lines')->count());
    }

    public function test_finance_audit_failure_rolls_back_all_journal_entries(): void
    {
        [$company, , , $people] = $this->fixture();
        config()->set('finance_controls.posting_enabled', true);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fail_finance_audit_test() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.action = 'finance.opening_cash_posted' THEN
        RAISE EXCEPTION 'Injected finance audit failure';
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        DB::statement('CREATE TRIGGER fail_finance_audit_test_trigger BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION fail_finance_audit_test()');
        try {
            app(FinanceControlService::class)->openCash(
                $people['owner'], $company->id, (string) Str::uuid(),
                'PKR', 800, 'Synthetic rollback audit test'
            );
            $this->fail('Audit rejected but journal still posted.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Injected finance audit failure', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_finance_audit_test_trigger ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS fail_finance_audit_test()');
        }
        $this->assertSame(0, DB::table('finance_journals')->count());
        $this->assertSame(0, DB::table('finance_journal_lines')->count());
    }

    public function test_quote_invalid_revision_foreign_company_and_unverified_owner_fail_closed(): void
    {
        [$company, $other, , $people, $booking] = $this->fixture();
        $service = app(FinanceControlService::class);
        try {
            $service->preview($people['owner'], $company->id, (string) Str::uuid(),
                $booking, 1, 'Stale booking revision');
            $this->fail('Old revision unexpectedly accepted.');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        try {
            $service->preview($people['owner'], $other->id, (string) Str::uuid(),
                $booking, 2, 'Other company');
            $this->fail('Foreign quote accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $people['owner']->forceFill(['email_verified_at' => null])->save();
        $this->expectException(AuthorizationException::class);
        $service->preview($people['owner'], $company->id, (string) Str::uuid(),
            $booking, 2, 'Unverified owner attempted preview');
    }

    public function test_immutability_applies_to_frozen_quote_and_cash_closeout(): void
    {
        [$company, , , $people, $booking] = $this->fixture();
        $service = app(FinanceControlService::class);
        $id = (string) Str::uuid();
        $service->preview($people['owner'], $company->id, $id,
            $booking, 2, 'Private frozen quote record');
        try {
            DB::transaction(fn () => DB::table('finance_quote_previews')
                ->where('id', $id)->update(['subtotal_minor' => 12345]));
            $this->fail('Preview snapshot was altered.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('history entries cannot be updated or deleted', $error->getMessage());
        }
        $this->assertSame(2000, DB::table('finance_quote_previews')->where('id', $id)->value('subtotal_minor'));
    }
}
