@extends('site.booking.layout', ['title' => 'Temporary hold created'])
@section('content')
<div class="bookwrap" style="max-width:760px">
    <span class="eyebrow">Email verified</span>
    <h1>Temporary hold created</h1>
    <section class="card">
        <p>Your slot is held until <strong>{{ $hold['expiresAt'] }}</strong>. This is not a confirmed booking and no payment has been taken.</p>
        <div class="summary-lines">
            <div><span>Required 5% deposit</span><b>{{ number_format($hold['depositMinor']/100, 2) }} PKR</b></div>
            <div><span>Hold reference</span><b><code id="hold-id">{{ $hold['holdId'] }}</code></b></div>
        </div>
        <p class="notice" style="margin-top:14px">Keep this cancellation token private: <code id="hold-token">{{ $hold['token'] }}</code></p>
        <div style="display:flex;gap:9px;flex-wrap:wrap">
            <form method="POST" action="/book/cancel">@csrf
                <input type="hidden" name="token" value="{{ $hold['token'] }}">
                <button class="btn danger" type="submit">Cancel this temporary hold</button>
            </form>
            <form method="POST" action="/book/confirm">@csrf
                <input type="hidden" name="token" value="{{ $hold['token'] }}">
                <button class="btn primary" type="submit">Confirm booking after verified deposit</button>
            </form>
            <a class="btn outline" href="/book">Start another booking</a>
        </div>
    </section>
</div>
@endsection
