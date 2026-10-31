<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Available halls</title>
<style>*{box-sizing:border-box}body{margin:0;font-family:system-ui,sans-serif;background:#f5f5f2;color:#17211b}main{max-width:960px;margin:auto;padding:32px 20px}.card{background:#fff;border:1px solid #cad5ce;border-radius:14px;padding:22px;margin:18px 0}h1{margin:0 0 8px}.price{font-size:1.5rem;font-weight:800}ul{padding-left:20px}button{padding:12px 20px;border:0;border-radius:8px;background:#23533d;color:#fff;font:inherit;font-weight:700;cursor:pointer}.meta{color:#526158}@media(max-width:520px){main{padding:20px 12px}.card{padding:16px}}</style>
</head><body><main>
<p><a href="/book">Change event details</a></p>
<h1>Step 2 of 3 — choose a hall</h1>
<p class="meta">{{ $quote['date'] }} · {{ ucfirst($quote['program']) }} · {{ $quote['guests'] }} guests</p>
@foreach ($quote['halls'] as $hall)
<div class="card">
<h2>{{ $hall['hallName'] }}</h2>
<p class="meta">Capacity up to {{ $hall['capacity'] }} guests</p>
<ul>
@foreach ($hall['lines'] as $line)
<li>{{ $line['label'] }} — {{ number_format($line['amountMinor']) }} {{ $hall['currencyCode'] }} minor units</li>
@endforeach
</ul>
<p class="price">Total: {{ number_format($hall['totalMinor']) }} {{ $hall['currencyCode'] }} minor units</p>
<form method="POST" action="/book/review">
@csrf
<input type="hidden" name="quote" value="{{ $token }}">
<input type="hidden" name="hall_id" value="{{ $hall['hallId'] }}">
<button type="submit">Review this hall</button>
</form>
</div>
@endforeach
</main></body></html>