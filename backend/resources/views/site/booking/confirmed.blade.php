<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Temporary hold created</title><style>body{font-family:system-ui,sans-serif;background:#f5f5f2;color:#17211b}main{max-width:760px;margin:auto;padding:40px 20px}.card{background:#fff;border:1px solid #cad5ce;border-radius:14px;padding:24px}button{padding:10px 16px;border:0;border-radius:8px;background:#8a1c1c;color:#fff;font:inherit}code{word-break:break-all}@media(max-width:520px){main{padding:20px 12px}}</style></head>
<body><main><div class="card">
<h1>Temporary hold created</h1>
<p>Your slot is held until <strong>{{ $hold['expiresAt'] }}</strong>. This is not a confirmed booking and no payment has been taken.</p>
<p>Required 5% deposit: <strong>{{ number_format($hold['depositMinor']) }} PKR minor units</strong>.</p>
<p>Keep this cancellation token private: <code>{{ $hold['token'] }}</code></p>
<form method="POST" action="/book/cancel">@csrf
<input type="hidden" name="token" value="{{ $hold['token'] }}">
<button type="submit">Cancel this temporary hold</button>
</form>
<p><a href="/book">Start another booking</a></p>
</div></main></body></html>