<?php

namespace App\Http\Controllers;

use App\Services\PublicBookingQuoteService;
use App\Http\Controllers\PublicSiteRequestGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
            'first_name' => ['required', 'string', 'min:1', 'max:60'],
            'last_name' => ['required', 'string', 'min:1', 'max:60'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['required', 'string', 'min:7', 'max:32'],
        ]);
        try {
            $quote = $this->open($data['quote'], $request);
            $pending = [
                'quote' => $quote, 'hall_id' => $data['hall_id'],
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                'email' => Str::lower(trim($data['email'])), 'phone' => $data['phone'],
                'idempotency_key' => $data['idempotency_key'],
            ];
            if (! $this->emailIsVerified($pending['email'])) {
                $request->session()->put('booking_pending', $pending);
                $this->issueCode($pending['email']);

                return redirect('/book/verify?pending='.urlencode(Crypt::encryptString(json_encode($pending, JSON_THROW_ON_ERROR))));
            }
            $hold = $this->createHold($pending);
        } catch (\Throwable $e) {
            $safe = $e instanceof \InvalidArgumentException ||
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                ? $e->getMessage() : 'Unable to create a temporary hold.';
            return back()->withInput()->withErrors(['booking' => $safe]);
        }

        return view('site.booking.confirmed', ['hold' => $hold]);
    }

    public function verifyForm(Request $request)
    {
        $this->guard();
        $pending = $this->pending($request);
        abort_unless(is_array($pending), 404);
        $verification = $request->session()->get('booking_email_verification');
        $seconds = isset($verification['last_sent'])
            ? max(0, 120 - (now()->timestamp - (int) $verification['last_sent'])) : 0;

        return view('site.booking.verify', [
            'email' => $pending['email'], 'resendAfter' => $seconds,
            'pendingToken' => Crypt::encryptString(json_encode($pending, JSON_THROW_ON_ERROR)),
        ]);
    }

    public function verifyCode(Request $request)
    {
        $this->guard();
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $pending = $this->pending($request);
        abort_unless(is_array($pending), 404);
        $fixed = config('public_booking.test_verification_code');
        $testBypass = app()->environment('testing') && is_string($fixed) && $fixed === $data['code'];
        $verification = $request->session()->get('booking_email_verification');
        if (! $testBypass) {
            if (! is_array($verification) || ($verification['email'] ?? null) !== $pending['email']) {
                return back()->withErrors(['code' => 'Request a new verification code.']);
            }
            if (now()->timestamp > (int) ($verification['expires_at'] ?? 0)) {
                return back()->withErrors(['code' => 'This verification code expired. Request a new one.']);
            }
            if ((int) ($verification['attempts'] ?? 0) >= 5) {
                return back()->withErrors(['code' => 'Too many attempts. Request a new code.']);
            }
            $request->session()->put('booking_email_verification',
                array_merge($verification, ['attempts' => (int) ($verification['attempts'] ?? 0) + 1]));
            if (! hash_equals((string) $verification['code_hash'], hash('sha256', $data['code']))) {
                return back()->withErrors(['code' => 'That verification code is not correct.']);
            }
        }
        try {
            $hold = $this->createHold($pending);
        } catch (\Throwable $e) {
            return back()->withErrors(['code' => $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Unable to create a temporary hold.']);
        }
        $request->session()->forget(['booking_pending', 'booking_email_verification']);

        return view('site.booking.confirmed', ['hold' => $hold]);
    }

    public function resendCode(Request $request)
    {
        $this->guard();
        $pending = $this->pending($request);
        abort_unless(is_array($pending), 404);
        $remaining = $this->issueCode($pending['email']);
        if ($remaining > 0) {
            return redirect('/book/verify')->withErrors([
                'code' => 'Please wait '.$remaining.' seconds before requesting another code.']);
        }

        return redirect('/book/verify')->with('status', 'A new verification code was sent.');
    }

    private function pending(Request $request): ?array
    {
        $session = $request->session()->get('booking_pending');
        if (is_array($session)) return $session;
        $token = $request->query('pending', $request->input('pending'));
        if (! is_string($token) || $token === '') return null;
        try { $decoded = json_decode(Crypt::decryptString($token), true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
        return is_array($decoded) ? $decoded : null;
    }

    private function createHold(array $pending): array
    {
        return app(\App\Services\PublicBookingHoldService::class)->create(
            $this->companyId(), $pending['quote'], $pending['hall_id'],
            ['name' => $pending['first_name'].' '.$pending['last_name'],
                'email' => $pending['email'], 'phone' => $pending['phone']],
            $pending['idempotency_key'],
        );
    }

    private function emailIsVerified(string $email): bool
    {
        $verification = session('booking_email_verification');
        return is_array($verification)
            && ($verification['email'] ?? null) === $email
            && isset($verification['verified_at'])
            && now()->timestamp - (int) $verification['verified_at'] <= 1800;
    }

    private function issueCode(string $email): int
    {
        $existing = session('booking_email_verification');
        $now = now()->timestamp;
        if (is_array($existing) && ($existing['email'] ?? null) === $email
            && isset($existing['last_sent']) && $now - (int) $existing['last_sent'] < 120) {
            return 120 - ($now - (int) $existing['last_sent']);
        }
        $fixed = config('public_booking.test_verification_code');
        $code = app()->environment('testing') && is_string($fixed) && preg_match('/^[0-9]{6}$/', $fixed)
            ? $fixed : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        session(['booking_email_verification' => [
            'email' => $email, 'code_hash' => hash('sha256', $code),
            'expires_at' => $now + 600, 'last_sent' => $now, 'attempts' => 0,
        ]]);
        try {
            Mail::raw("Your Zargul booking verification code is {$code}. It expires in 10 minutes.",
                fn ($message) => $message->to($email)->subject('Verify your Zargul booking email'));
        } catch (\Throwable) {
            // The code remains valid in-session if local SMTP is not configured.
        }

        return 0;
    }

    public function confirm(Request $request)
    {
        $this->guard();
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        try {
            $confirmed = app(\App\Services\PublicBookingConfirmationService::class)
                ->confirm($this->companyId(), $data['token']);
        } catch (\Throwable $e) {
            $safe = $e instanceof \InvalidArgumentException ||
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                ? $e->getMessage() : 'Unable to confirm this booking.';
            return back()->withErrors(['booking' => $safe]);
        }

        return view('site.booking.confirmed-booking', ['confirmation' => $confirmed]);
    }

    public function status(Request $request)
    {
        $this->guard();
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $row = \Illuminate\Support\Facades\DB::table('public_booking_confirmations')
            ->where('company_id', $this->companyId())
            ->where('token_hash', hash('sha256', $data['token']))->first();
        abort_if($row === null, 404);

        return view('site.booking.status', ['booking' => $row]);
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