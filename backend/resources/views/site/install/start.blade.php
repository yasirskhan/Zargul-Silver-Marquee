<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install Zargul VenueDesk</title>
<style>
:root{--forest:#123d2d;--gold:#b99542;--cream:#f6f4ee;--line:#e3e4db}
*{box-sizing:border-box}body{margin:0;background:var(--cream);color:#172b24;font:14px/1.55 system-ui,-apple-system,'Segoe UI',sans-serif}
.shell{max-width:980px;margin:auto;padding:38px 20px}.brand{display:flex;align-items:center;gap:12px;font:700 16px Georgia,serif;letter-spacing:.06em;color:var(--forest);margin-bottom:22px}
.brandmark{display:grid;place-items:center;height:40px;width:40px;border:1px solid var(--gold);color:var(--gold);font-size:22px}.brand small{display:block;font:600 8px system-ui;letter-spacing:.2em;color:#7a6947}
.layout{display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start}.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:22px;box-shadow:0 4px 18px #173b290f}
.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.18em;font-weight:750;color:var(--gold)}h1{font:600 2rem/1.15 Georgia,serif;color:var(--forest);margin:6px 0 8px}h2{font:600 1.25rem Georgia,serif;color:var(--forest);margin:0 0 10px}
.check{display:flex;gap:9px;padding:9px 0;border-bottom:1px solid var(--line);font-size:12px}.check:last-child{border:0}.dot{flex:0 0 18px;height:18px;border-radius:50%;display:grid;place-items:center;font-size:11px;background:#f6dadd;color:#963342}.dot.ok{background:#dff0e3;color:#1d6b38}.check strong{display:block}.check span{color:#68776e}
label{display:grid;gap:5px;margin:12px 0;font-size:12px;font-weight:700;color:#385048}input{width:100%;padding:10px;border:1px solid #cfd8cd;border-radius:7px;font:inherit;min-height:40px}
button{padding:10px 16px;border:0;border-radius:7px;background:var(--forest);color:#fff;font:inherit;font-weight:700;cursor:pointer}.btn-gold{background:linear-gradient(120deg,#c0a14b,#a57d2c)}button:disabled{opacity:.45;cursor:not-allowed}
.error{color:#a01818;background:#fff5f5;border:1px solid #e1bcc1;padding:10px;border-radius:7px}
@media(max-width:800px){.layout{grid-template-columns:1fr}.shell{padding:24px 14px}}
</style></head><body><div class="shell">
<div class="brand"><span class="brandmark">✦</span><span>ZARGUL VENUEDESK<small>FIRST-TIME INSTALLATION</small></span></div>
<div class="layout">
    <aside class="card">
        <span class="eyebrow">Server readiness</span>
        <h2>Installation checks</h2>
        <p style="font-size:12px;color:#68776e">Owner creation is available only after these checks pass.</p>
        @foreach ($checks as $check)
            <div class="check">
                <span class="dot {{ $check['ok'] ? 'ok' : '' }}">{{ $check['ok'] ? '✓' : '!' }}</span>
                <div><strong>{{ $check['label'] }}</strong><span>{{ $check['detail'] }}</span></div>
            </div>
        @endforeach
    </aside>
    <main class="card">
        <span class="eyebrow">Step 1 of 2</span>
        <h1>Create the installation Owner</h1>
        <p>Enter the operator installation token, company and first Owner. VenueDesk issues a one-time owner-creation link; no default password exists.</p>
        @if ($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
        @if (! $ready)
            <p class="error" role="status">Resolve the failed readiness checks before continuing.</p>
        @endif
        <form method="POST" action="/install">@csrf
            <label>Operator installation token<input type="password" name="operator_token" required maxlength="256" autocomplete="off"></label>
            <label>Company legal name<input name="company_name" required maxlength="120" value="{{ old('company_name') }}"></label>
            <label>Owner name<input name="owner_name" required maxlength="120" value="{{ old('owner_name') }}"></label>
            <label>Owner email<input type="email" name="email" required maxlength="254" value="{{ old('email') }}"></label>
            <button type="submit" class="btn-gold" @disabled(! $ready)>Create one-time Owner link</button>
        </form>
    </main>
</div>
</div></body></html>
