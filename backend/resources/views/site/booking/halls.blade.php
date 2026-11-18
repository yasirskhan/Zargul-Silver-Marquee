@extends('site.booking.layout', ['title' => 'Available halls'])
@section('content')
<div class="bookwrap">
    <span class="eyebrow">Approved venue pricing</span>
    <h1>Step 2 of 3 — choose a hall</h1>
    <p class="meta">{{ $quote['date'] }} · {{ ucfirst($quote['program']) }} · {{ $quote['guests'] }} guests</p>
    <div class="steps"><span class="step done">1. Event details</span><span class="step active">2. Choose hall</span><span class="step">3. Review &amp; verify</span></div>
    <div class="hall-list">
        @foreach ($quote['halls'] as $hall)
            <div class="card">
                <div>
                    <span class="eyebrow">Available hall</span>
                    <h2>{{ $hall['hallName'] }}</h2>
                    <p class="meta">Capacity up to {{ $hall['capacity'] }} guests</p>
                    <ul>
                        @foreach ($hall['lines'] as $line)
                            <li>{{ $line['label'] }} — {{ number_format($line['amountMinor']/100, 2) }} {{ $hall['currencyCode'] }}</li>
                        @endforeach
                    </ul>
                    <p class="price">Total: {{ number_format($hall['totalMinor']/100, 2) }} {{ $hall['currencyCode'] }}</p>
                </div>
                <div>
                    <div style="height:150px;border-radius:9px;background:linear-gradient(135deg,#163e30,#2b5740);display:grid;place-items:center;color:#e7c87b;font:600 2rem Georgia,serif">✦</div>
                    <form method="POST" action="/book/review" style="margin-top:12px">@csrf
                        <input type="hidden" name="quote" value="{{ $token }}">
                        <input type="hidden" name="hall_id" value="{{ $hall['hallId'] }}">
                        <button class="btn primary" type="submit" style="width:100%">Review this hall</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
    <p style="margin-top:14px"><a class="btn outline" href="/book">Change event details</a></p>
</div>
@endsection
