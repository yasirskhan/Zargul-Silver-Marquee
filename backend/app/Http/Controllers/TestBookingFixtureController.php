<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Hall;
use App\Models\User;
use App\Services\PublicBookingPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Isolated Phase 10 browser fixture; unavailable outside testing. */
final class TestBookingFixtureController
{
    public function hall()
    {
        $this->guard();
        $website = DB::table('websites')->where('name', 'Phase 10 Browser Fixture')->first();
        abort_if($website === null, 404);
        $owner = User::where('email', 'browser-e2e-inventory-owner-c@example.test')->firstOrFail();
        $hall = Hall::firstOrCreate(
            ['company_id' => $website->company_id, 'slug' => 'phase10-booking-hall'],
            ['name' => 'Phase 10 Booking Hall', 'capacity' => 200],
        );
        $hall->forceFill(['is_active' => true, 'is_bookable' => true, 'capacity' => 200])->save();
        if ((int) $hall->guest_pricing_revision === 0) {
            DB::table('hall_guest_pricing_revisions')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $website->company_id,
                'hall_id' => $hall->id, 'revision_number' => 1,
                'tiers' => json_encode([
                    ['minGuests' => 1, 'maxGuests' => 100, 'priceMinor' => 100000],
                    ['minGuests' => 101, 'maxGuests' => 200, 'priceMinor' => 180000],
                ], JSON_THROW_ON_ERROR), 'currency_code' => 'PKR',
                'changed_by' => $owner->id, 'reason' => 'Phase 10 browser fixture tiers',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('halls')->where('id', $hall->id)->update(['guest_pricing_revision' => 1]);
        }
        if ((int) $hall->program_rules_revision === 0) {
            DB::table('hall_program_rule_revisions')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $website->company_id,
                'hall_id' => $hall->id, 'revision_number' => 1, 'currency_code' => 'PKR',
                'rules' => json_encode([
                    'morning' => ['start' => '09:00', 'end' => '13:00', 'rentalRateMinor' => 20000],
                    'noon' => ['start' => '13:00', 'end' => '17:00', 'rentalRateMinor' => 20000],
                    'evening' => ['start' => '18:00', 'end' => '23:00', 'rentalRateMinor' => 30000],
                ], JSON_THROW_ON_ERROR), 'changed_by' => $owner->id,
                'reason' => 'Phase 10 browser fixture programs', 'created_at' => now(),
            ]);
            DB::table('halls')->where('id', $hall->id)->update(['program_rules_revision' => 1]);
        }

        return response()->json(['hallId' => $hall->id, 'companyId' => $website->company_id]);
    }

    public function reset()
    {
        $this->guard();
        $website = DB::table('websites')->where('name', 'Phase 10 Browser Fixture')->first();
        abort_if($website === null, 404);
        $hallId = DB::table('halls')->where('company_id', $website->company_id)
            ->where('slug', 'phase10-booking-hall')->value('id');
        if ($hallId) {
            $holdIds = DB::table('public_booking_holds')->where('hall_id', $hallId)
                ->pluck('id')->all();
            DB::table('public_booking_hold_events')->whereIn('hold_id', $holdIds)->delete();
            DB::table('public_booking_payment_evidence')->whereIn('hold_id', $holdIds)->delete();
            DB::table('public_booking_confirmations')->where('hall_id', $hallId)->delete();
            DB::table('public_booking_holds')->where('hall_id', $hallId)->delete();
        }

        return response()->json(['reset' => true]);
    }
    public function verify(Request $request, PublicBookingPaymentService $payments)
    {
        $this->guard();
        $data = $request->validate([
            'company_id' => ['required', 'uuid'], 'hold_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:120'],
        ]);
        $hold = DB::table('public_booking_holds')->where('company_id', $data['company_id'])
            ->where('id', $data['hold_id'])->firstOrFail();
        $secret = (string) config('public_booking.synthetic_verifier_secret');
        $canonical = implode('|', [$data['company_id'], $data['hold_id'], $data['reference'],
            (string) $hold->deposit_minor, $hold->currency_code, 'ci-verifier']);
        $verified = $payments->verifySynthetic($data['company_id'], $data['hold_id'],
            $data['reference'], (int) $hold->deposit_minor, $hold->currency_code,
            'ci-verifier', hash_hmac('sha256', $canonical, $secret));

        return response()->json($verified);
    }

    private function guard(): void
    {
        abort_unless(app()->environment('testing')
            && config('public_site.test_fallback') === true, 404);
    }
}