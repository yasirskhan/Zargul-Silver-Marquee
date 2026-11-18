@extends('site.booking.layout', ['title' => 'Review and request hold'])
@section('content')
<div class="bookwrap">
    <span class="eyebrow">Final step</span>
    <h1>Step 3 of 3 — review and request hold</h1>
    <p class="meta">{{ $quote['date'] }} · {{ ucfirst($quote['program']) }} · {{ $quote['guests'] }} guests</p>
    <div class="steps"><span class="step done">1. Event details</span><span class="step done">2. Choose hall</span><span class="step active">3. Review &amp; verify</span></div>
    <div class="booking-layout">
        <section class="card">
            <h2>Your details</h2>
            <p class="meta">We verify your email before any date can be held.</p>
            @if ($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
            <form method="POST" action="/book/submit">@csrf
                <input type="hidden" name="quote" value="{{ $token }}">
                <input type="hidden" name="hall_id" value="{{ $hall['hallId'] }}">
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <div class="fields">
                    <label class="field">First name<input name="first_name" required maxlength="60" value="{{ old('first_name') }}"></label>
                    <label class="field">Last name<input name="last_name" required maxlength="60" value="{{ old('last_name') }}"></label>
                    <label class="field">Email<input type="email" name="email" required maxlength="254" value="{{ old('email') }}"></label>
                    <label class="field">Phone / WhatsApp<input name="phone" required minlength="7" maxlength="32" value="{{ old('phone') }}"></label>
                </div>
                <div class="notice">You will receive a six-digit verification code. The temporary hold is created only after verification.</div>
                <button class="btn gold" type="submit">Send verification code</button>
            </form>
        </section>
        <aside class="card">
            <h2>{{ $hall['hallName'] }}</h2>
            <div class="summary-lines">
                <div><span>Date</span><b>{{ $quote['date'] }}</b></div>
                <div><span>Program</span><b>{{ ucfirst($quote['program']) }}</b></div>
                <div><span>Guests</span><b>{{ $quote['guests'] }}</b></div>
                @foreach ($hall['lines'] as $line)
                    <div><span>{{ $line['label'] }}</span><b>{{ number_format($line['amountMinor']/100,2) }} {{ $hall['currencyCode'] }}</b></div>
                @endforeach
                <div class="total"><span>Total</span><b>{{ number_format($hall['totalMinor']/100,2) }} {{ $hall['currencyCode'] }}</b></div>
            </div>
            <p style="margin-top:12px"><a class="btn outline" href="/book">Change event details</a></p>
        </aside>
    </div>
</div>
@endsection
