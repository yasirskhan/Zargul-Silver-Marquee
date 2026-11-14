<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install VenueDesk</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f5f2;color:#17211b;margin:0}main{max-width:40rem;margin:4rem auto;padding:0 1.25rem}.card{background:#fff;border:1px solid #cad5ce;border-radius:16px;padding:2rem}h1{font-family:Georgia,serif}label{display:block;margin:1rem 0}input{width:100%;box-sizing:border-box;padding:.8rem;border:1px solid #aebbb3;border-radius:8px}button{padding:.85rem 1.3rem;border:0;border-radius:8px;background:#1f4d38;color:#fff;font-weight:700}.error{color:#a01818;margin:.75rem 0}</style></head><body><main><div class="card"><h1>Create the installation Owner</h1>
@if($installed)<p>This installation already has an Owner. Use the admin sign-in.</p>@else
<p>Enter the operator installation token, company and first Owner. VenueDesk will issue a one-time owner-creation link; no default password exists.</p>
@if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
<form method="POST" action="/install">@csrf
<label>Operator installation token<input type="password" name="operator_token" required maxlength="256" autocomplete="off"></label>
<label>Company legal name<input name="company_name" required maxlength="120" value="{{ old('company_name') }}"></label>
<label>Owner name<input name="owner_name" required maxlength="120" value="{{ old('owner_name') }}"></label>
<label>Owner email<input type="email" name="email" required maxlength="254" value="{{ old('email') }}"></label>
<button type="submit">Create one-time Owner link</button></form>@endif
</div></main></body></html>
