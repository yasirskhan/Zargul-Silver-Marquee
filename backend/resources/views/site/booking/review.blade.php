<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Review your booking</title>
<style>*{box-sizing:border-box}body{margin:0;font-family:system-ui,sans-serif;background:#f5f5f2;color:#17211b}main{max-width:960px;margin:auto;padding:32px 20px}.card{background:#fff;border:1px solid #cad5ce;border-radius:14px;padding:22px;margin:18px 0}h1{margin:0 0 8px}.price{font-size:1.5rem;font-weight:800}.error{color:#a01818;border:1px solid #a01818;padding:10px;border-radius:8px}button{padding:12px 20px;border:0;border-radius:8px;background:#23533d;color:#fff;font:inherit;font-weight:700;cursor:pointer}@media(max-width:520px){main{padding:20px 12px}.card{padding:16px}}</style>
</head><body><main>
<p><a href="/book">Start again</a></p>
<h1>Step 3 of 3 — review and request hold</h1>
@if ($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
<div class="card">
<h2>{{ $hall['hallName'] }}</h2>
<p>{{ $quote['date'] }} · {{ ucfirst($quote['program']) }} · {{ $quote['guests'] }} guests</p>
<ul>
@foreach ($hall['lines'] as $line)
<li>{{ $line['label'] }} — {{ number_format($line['amountMinor']) }} {{ $hall['currencyCode'] }} minor units</li>
@endforeach
</ul>
<p class="price">Total: {{ number_format($hall['totalMinor']) }} {{ $hall['currencyCode'] }} minor units</p>
<p><strong>Deposit required:</strong> {{ number_format((int) ceil($hall['totalMinor'] * 0.05)) }} {{ $hall['currencyCode'] }} minor units (5%)</p>
<p>Your selections and price revisions are revalidated on every step. This page does not create a booking or take payment.</p>
<form method="POST" action="/book/submit">
@csrf
<input type="hidden" name="quote" value="{{ $token }}">
<input type="hidden" name="hall_id" value="{{ $hall['hallId'] }}">
<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<label>Your name <input name="customer_name" required maxlength="120"></label>
<label>Email <input type="email" name="email" maxlength="254"></label>
<label>Phone <input name="phone" maxlength="32"></label>
<p><button type="submit">Request temporary hold</button></p>
</form>
</div>
</main></body></html>