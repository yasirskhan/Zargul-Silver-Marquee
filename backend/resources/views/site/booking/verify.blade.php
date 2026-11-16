<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verify your email | Zargul Silver Marquee</title>
<style>:root{--forest:#123d2d;--gold:#b99542;--cream:#f7f4ec;--line:#e3e4db}*{box-sizing:border-box}body{margin:0;background:var(--cream);color:#172b24;font:14px/1.5 system-ui,sans-serif}.wrap{max-width:520px;margin:0 auto;padding:48px 20px}.brand{font:700 24px Georgia,serif;color:var(--forest);text-align:center;margin-bottom:18px}.brand small{display:block;font:600 9px system-ui;letter-spacing:.2em;color:#7a6947}.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:28px;box-shadow:0 4px 18px #173b2910}h1{font:700 30px Georgia,serif;color:var(--forest);margin:0 0 8px}p{color:#68776e}.field{display:grid;gap:6px;margin:16px 0;font-size:12px;font-weight:700}.field input{border:1px solid #cfd8cd;border-radius:8px;padding:12px;font:inherit;letter-spacing:.25em;text-align:center;font-size:20px}button{border:0;border-radius:8px;padding:12px 16px;font:inherit;font-weight:700;cursor:pointer}.primary{background:var(--forest);color:#fff;width:100%}.link{background:none;color:#8a6a3e;text-decoration:underline;padding:0;font-size:13px}.error{background:#fcebea;color:#a33;padding:10px;border-radius:8px;font-size:13px;margin:10px 0}.status{background:#edf7f0;color:#25543a;padding:10px;border-radius:8px;font-size:13px;margin:10px 0}.note{font-size:12px;color:#68776e;margin-top:14px}</style></head>
<body><main class="wrap"><div class="brand">✦ ZARGUL SILVER MARQUEE<small>WEDDINGS &amp; CELEBRATIONS</small></div>
<section class="card"><h1>Verify your email</h1><p>We sent a six-digit code to <strong>{{ $email }}</strong>. Enter it below before we hold your event date.</p>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
<form method="POST" action="/book/verify">@csrf
<input type="hidden" name="pending" value="{{ $pendingToken }}">
<label class="field">Verification code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" autofocus></label>
<button class="primary" type="submit">Verify and hold my date</button>
</form>
<form method="POST" action="/book/verify/resend" style="margin-top:14px;text-align:center">@csrf
<input type="hidden" name="pending" value="{{ $pendingToken }}">
<button class="link" id="resend" type="submit" <?= $resendAfter > 0 ? 'disabled' : '' ?>>Resend code <span id="count" <?= $resendAfter > 0 ? '' : 'hidden' ?>>({{ $resendAfter }}s)</span></button>
</form>
<p class="note">The code expires in 10 minutes. Temporary holds are released automatically if the code is not verified or payment is not completed in time.</p>
</section></main>
<script>let n={{ (int) $resendAfter }};const b=document.getElementById('resend'),c=document.getElementById('count');if(n>0&&b){b.disabled=true;const t=setInterval(()=>{n--;if(c)c.textContent=n;if(n<=0){clearInterval(t);b.disabled=false;b.textContent='Resend code';}},1000);}</script>
</body></html>
