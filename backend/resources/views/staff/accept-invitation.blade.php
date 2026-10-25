<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Accept staff invitation</title>
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
    <h1>Accept staff invitation</h1>
    <p>Create your own password. Access remains unavailable until email verification and MFA enrolment.</p>
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="POST" action="{{ route('staff.invitation.accept', ['token' => $token]) }}">
        @csrf
        <label>Your name <input name="name" value="{{ old('name') }}" maxlength="120" required></label>
        <label>Password (at least 12 characters) <input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"></label>
        <button type="submit">Accept invitation</button>
    </form>
</main>
</body>
</html>
