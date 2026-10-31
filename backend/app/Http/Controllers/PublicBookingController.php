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
            return back()->withInput()->withErrors(['booking' =>
                $e instanceof \InvalidArgumentException || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                    ? $e->getMessage()
                    : 'Unable to calculate an approved quote for those details.']);
        }

        return view('site.booking.halls', [
            'quote' => $quote,
            'token' => $this->seal($quote, $request),
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
            $sealed = json_decode(Crypt::decryptString($data['quote']), true, 32, JSON_THROW_ON_ERROR);
            abort_unless(hash_equals($request->getHost(), (string) ($sealed['host'] ?? ''))
                && hash_equals($this->companyId(), (string) ($sealed['companyId'] ?? '')), 403);
            $quote = $sealed['quote'] ?? null;
            abort_unless(is_array($quote), 422);
            $review = $this->quotes->revalidate($quote, $data['hall_id']);
        } catch (Throwable $e) {
            return redirect('/book')->withErrors(['booking' => $e->getMessage()]);
        }

        return view('site.booking.review', [
            'quote' => $review['quote'],
            'hall' => $review['hall'],
            'token' => $this->seal($review['quote'], $request),
        ]);
    }

    public function submit(Request $request)
    {
        $this->guard();
        $data = $request->validate([
            'quote' => ['required', 'string', 'max:20000'],
            'hall_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);
        try {
            $quote = $this->open($data['quote'], $request);
            $hold = app(\App\Services\PublicBookingHoldService::class)->create(
                $this->companyId(), $quote, $data['hall_id'],
                ['name' => $data['customer_name'], 'email' => $data['email'] ?? '',
                    'phone' => $data['phone'] ?? ''],
                $data['idempotency_key'],
            );
        } catch (\Throwable $e) {
            $safe = $e instanceof \InvalidArgumentException ||
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                ? $e->getMessage() : 'Unable to create a temporary hold.';
            return back()->withInput()->withErrors(['booking' => $safe]);
        }

        return view('site.booking.confirmed', ['hold' => $hold]);
    }

    public function cancel(Request $request)
    {
        $this->guard();
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        app(\App\Services\PublicBookingHoldService::class)->cancel($this->companyId(), $data['token']);

        return view('site.booking.cancelled');
    }

    private function open(string $sealed, Request $request): array
    {
        $payload = json_decode(Crypt::decryptString($sealed), true, 32, JSON_THROW_ON_ERROR);
        abort_unless(is_array($payload)
            && hash_equals($request->getHost(), (string) ($payload['host'] ?? ''))
            && hash_equals($this->companyId(), (string) ($payload['companyId'] ?? '')), 403);
        $quote = $payload['quote'] ?? null;
        abort_unless(is_array($quote), 422);

        return $quote;
    }

    private function seal(array $quote, Request $request): string
    {
        return Crypt::encryptString(json_encode([
            'quote' => $quote, 'host' => $request->getHost(),
            'companyId' => $this->companyId(),
        ], JSON_THROW_ON_ERROR));
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