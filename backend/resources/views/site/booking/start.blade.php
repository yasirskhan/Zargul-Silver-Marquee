<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Book your event</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,sans-serif;background:#f5f5f2;color:#17211b}
main{max-width:960px;margin:auto;padding:32px 20px}.card{background:#fff;border:1px solid #cad5ce;border-radius:14px;padding:24px;margin:20px 0}
h1{font-size:clamp(2rem,5vw,3.2rem);margin:0 0 8px}.grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
label{display:grid;gap:6px;font-weight:650}input,select{width:100%;padding:12px;border:1px solid #9fb0a5;border-radius:8px;font:inherit}
button{padding:12px 20px;border:0;border-radius:8px;background:#23533d;color:#fff;font:inherit;font-weight:700;cursor:pointer}
.error{color:#a01818;border:1px solid #a01818;padding:10px;border-radius:8px}.price{font-size:1.4rem;font-weight:800}
@media(max-width:520px){main{padding:20px 12px}.card{padding:16px}}
</style>
</head>
<body><main>
<p><a href="/">Back to venue</a></p>
<h1>Book your event</h1>
<p>Step 1 of 3 — choose your date, program and guest count. Prices are calculated by the venue from approved published pricing.</p>
<div class="card">
@if ($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
<form method="POST" action="/book/quote">
@csrf
<div class="grid">
<label>Event date<input type="date" name="event_date" required value="{{ old('event_date') }}"></label>
<label>Program<select name="program" required>
<option value="morning" @selected(old('program')==='morning')>Morning</option>
<option value="noon" @selected(old('program')==='noon')>Noon</option>
<option value="evening" @selected(old('program')==='evening')>Evening</option>
</select></label>
<label>Exact guest count<input type="number" name="guests" min="1" max="100000" required value="{{ old('guests') }}"></label>
</div>
<p><button type="submit">See available halls and price</button></p>
</form>
</div>
</main></body></html>