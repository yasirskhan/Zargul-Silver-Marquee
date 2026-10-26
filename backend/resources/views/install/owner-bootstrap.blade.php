<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Create installation Owner</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 34rem; margin: 3rem auto; padding: 1rem; }
        label { display: block; margin: 1rem 0; }
        input { width: 100%; padding: .75rem; }
        button { padding: .8rem 1.2rem; }
        .error { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <h1>Create the installation Owner</h1>
    <p>This one-time link creates the first verified Owner. You must then sign in and enrol in MFA. No default password is created.</p>
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="POST" action="{{ route('installation.owner.accept', ['token' => $token]) }}">
        @csrf
        <label>Owner password (at least 12 characters) <input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"></label>
        <button type="submit">Create Owner</button>
    </form>
</main>
</body>
</html>
