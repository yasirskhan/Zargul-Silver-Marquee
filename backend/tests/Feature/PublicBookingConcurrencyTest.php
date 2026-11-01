<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Hall;
use App\Models\User;
use App\Services\PublicBookingConfirmationService;
use App\Services\PublicBookingHoldService;
use App\Services\PublicBookingPaymentService;
use App\Services\PublicBookingQuoteService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('phase9-booking-process')]
final class PublicBookingConcurrencyTest extends TestCase
{
    public function test_competing_holds_and_confirmations_have_one_owner(): void
    {
        config()->set('public_booking.enabled', true);
        config()->set('public_booking.holds_enabled', true);
        config()->set('public_booking.synthetic_test_boundary', true);
        config()->set('public_booking.synthetic_verifier_secret', str_repeat('s', 32));
        $suffix = substr((string) Str::uuid(), 0, 8);
        $company = Company::create(['name' => 'Concurrent Booking '.$suffix,
            'slug' => 'concurrent-booking-'.$suffix]);
        $hall = Hall::create(['company_id' => $company->id, 'name' => 'Race Hall '.$suffix,
            'slug' => 'race-hall-'.$suffix, 'capacity' => 200, 'is_bookable' => true]);
        $owner = User::create(['name' => 'Owner', 'email' => 'race-'.$suffix.'@example.test',
            'password' => 'test-only']);
        DB::table('hall_guest_pricing_revisions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'hall_id' => $hall->id,
            'revision_number' => 1, 'tiers' => json_encode([
                ['minGuests' => 1, 'maxGuests' => 200, 'priceMinor' => 100000],
            ], JSON_THROW_ON_ERROR), 'currency_code' => 'PKR', 'changed_by' => $owner->id,
            'reason' => 'Race tiers', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hall_program_rule_revisions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company->id, 'hall_id' => $hall->id,
            'revision_number' => 1, 'currency_code' => 'PKR', 'rules' => json_encode([
                'morning' => ['start' => '09:00', 'end' => '13:00', 'rentalRateMinor' => 10000],
                'noon' => ['start' => '13:00', 'end' => '17:00', 'rentalRateMinor' => 10000],
                'evening' => ['start' => '18:00', 'end' => '23:00', 'rentalRateMinor' => 10000],
            ], JSON_THROW_ON_ERROR), 'changed_by' => $owner->id, 'reason' => 'Race programs',
            'created_at' => now(),
        ]);
        DB::table('halls')->where('id', $hall->id)->update([
            'guest_pricing_revision' => 1, 'program_rules_revision' => 1, 'addon_catalog_revision' => 0,
        ]);
        $quote = app(PublicBookingQuoteService::class)
            ->quote($company->id, '2027-12-15', 'evening', 100);
        $dir = sys_get_temp_dir().'/booking-race-'.Str::uuid();
        mkdir($dir, 0700, true);
        $quoteFile = $dir.'/quote.json';
        file_put_contents($quoteFile, json_encode($quote, JSON_THROW_ON_ERROR));
        $start = $dir.'/start';

        $workers = [];
        foreach (['a', 'b'] as $suffixKey) {
            $result = $dir.'/hold-'.$suffixKey;
            $ready = $dir.'/ready-hold-'.$suffixKey;
            $process = proc_open([PHP_BINARY, base_path('tests/Support/booking_worker.php'),
                'hold', $company->id, $hall->id, $quoteFile, (string) Str::uuid(), $result, $ready, $start],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $workers[$suffixKey] = compact('process', 'pipes', 'result', 'ready');
        }
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (is_file($workers['a']['ready']) && is_file($workers['b']['ready'])) break;
            usleep(20_000);
        }
        touch($start);
        foreach ($workers as $worker) {
            stream_get_contents($worker['pipes'][1]);
            stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $this->assertSame(0, proc_close($worker['process']));
        }
        $results = [trim((string) file_get_contents($workers['a']['result'])),
            trim((string) file_get_contents($workers['b']['result']))];
        sort($results);
        $this->assertSame(['conflict', 'ok'], $results);
        $this->assertSame(1, DB::table('public_booking_holds')->where('company_id', $company->id)->count());
        $hold = DB::table('public_booking_holds')->where('company_id', $company->id)->first();
        $token = Crypt::decryptString($hold->token_ciphertext);
        $canonical = implode('|', [$company->id, $hold->id, 'RACE-REF',
            (string) $hold->deposit_minor, 'PKR', 'ci-verifier']);
        app(PublicBookingPaymentService::class)->verifySynthetic($company->id, $hold->id,
            'RACE-REF', (int) $hold->deposit_minor, 'PKR', 'ci-verifier',
            hash_hmac('sha256', $canonical, str_repeat('s', 32)));

        $confirmStart = $dir.'/confirm-start';
        $confirmers = [];
        foreach (['a', 'b'] as $key) {
            $result = $dir.'/confirm-'.$key;
            $ready = $dir.'/ready-confirm-'.$key;
            $process = proc_open([PHP_BINARY, base_path('tests/Support/booking_worker.php'),
                'confirm', $company->id, $hall->id, $quoteFile, $token, $result, $ready, $confirmStart],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $confirmers[$key] = compact('process', 'pipes', 'result', 'ready');
        }
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (is_file($confirmers['a']['ready']) && is_file($confirmers['b']['ready'])) break;
            usleep(20_000);
        }
        touch($confirmStart);
        foreach ($confirmers as $worker) {
            stream_get_contents($worker['pipes'][1]);
            stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $this->assertSame(0, proc_close($worker['process']));
        }
        $results = [trim((string) file_get_contents($confirmers['a']['result'])),
            trim((string) file_get_contents($confirmers['b']['result']))];
        sort($results);
        $this->assertSame(['conflict', 'ok'], $results);
        $this->assertSame(1, DB::table('public_booking_confirmations')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('public_booking_holds')->where('company_id', $company->id)->count());
    }
}