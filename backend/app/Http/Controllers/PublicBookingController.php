<?php

namespace App\Http\Controllers;

use App\Services\PublicBookingQuoteService;
use App\Http\Controllers\PublicSiteRequestGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class PublicBookingController
{
    public function __construct(
        private readonly PublicSiteRequestGate $gate,
        private readonly PublicBookingQuoteService $quotes,
    ) {}

    public function show()
    {
        $this->guard();

        return view('site.booking.start');
    }

    public function quote(Request $request)
    {
        $this->guard();
        $data = $request->validate([
            'event_date' => ['required', 'date_format:Y-m-d'],
            'program' => ['required', 'in:morning,noon,evening'],
            'guests' => ['required', 'integer', 'min:1', 'max:100000'],
            'addons' => ['array', 'max:20'],
            'addons.*' => ['string', 'max:64'],
        ]);
        try {
            $companyId = $this->companyId();
            $quote = $this->quotes->quote($companyId, $data['event_date'],
                $data['program'], (int) $data['guests'], $data['addons'] ?? []);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['booking' => $e->getMessage()]);
        }

        return view('site.booking.halls', [
            'quote' => $quote,
            'token' => Crypt::encryptString(json_encode($quote, JSON_THROW_ON_ERROR)),
        ]);
    }

    public function review(Request $request)
    {
        $this->guard();
        $data = $request->validate([
            'quote' => ['required', 'string', 'max:20000'],
            'hall_id' => ['required', 'uuid'],
        ]);
        try {
            $quote = json_decode(Crypt::decryptString($data['quote']), true, 32, JSON_THROW_ON_ERROR);
            $review = $this->quotes->revalidate($quote, $data['hall_id']);
        } catch (Throwable $e) {
            return redirect('/book')->withErrors(['booking' => $e->getMessage()]);
        }

        return view('site.booking.review', [
            'quote' => $review['quote'],
            'hall' => $review['hall'],
            'token' => Crypt::encryptString(json_encode($review['quote'], JSON_THROW_ON_ERROR)),
        ]);
    }

    public function submit(Request $request)
    {
        $this->guard();

        return back()->withErrors(['booking' =>
            'Temporary booking holds and verified deposits are not enabled yet. No booking or payment was created.']);
    }

    private function guard(): void
    {
        abort_unless(config('public_booking.enabled') === true, 404);
        $this->gate->authorize(request());
    }

    private function companyId(): string
    {
        $company = config('public_site.company_id');
        if (is_string($company) && $company !== '') {
            return $company;
        }
        if (app()->environment('testing') && config('public_site.test_fallback') === true) {
            return (string) \Illuminate\Support\Facades\DB::table('websites')
                ->where('name', 'Phase 10 Browser Fixture')->value('company_id');
        }
        abort(503);
    }
}