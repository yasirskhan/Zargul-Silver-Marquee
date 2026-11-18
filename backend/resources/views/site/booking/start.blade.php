@extends('site.booking.layout', ['title' => 'Book your event'])
@section('content')
<div class="bookwrap">
    <span class="eyebrow">Plan your celebration</span>
    <h1>Book your event</h1>
    <p class="meta">Three daily programs · live approved pricing · temporary hold after email verification.</p>
    <div class="steps"><span class="step active">1. Event details</span><span class="step">2. Choose hall</span><span class="step">3. Review &amp; verify</span></div>
    <div class="booking-layout">
        <section class="card">
            <h2>Tell us about your event</h2>
            @if ($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
            <form method="POST" action="/book/quote">@csrf
                <div class="fields">
                    <label class="field">Event date<input type="date" name="event_date" required value="{{ old('event_date') }}"></label>
                    <label class="field">Program<select name="program" required>
                        <option value="morning" @selected(old('program')==='morning')>Morning</option>
                        <option value="noon" @selected(old('program')==='noon')>Noon</option>
                        <option value="evening" @selected(old('program')==='evening')>Evening</option>
                    </select></label>
                    <label class="field">Exact guest count<input type="number" name="guests" min="1" max="100000" required value="{{ old('guests') }}" placeholder="Number of guests"></label>
                </div>
                <div class="notice">Selecting a date does not reserve a hall. Availability and pricing are checked on the next step.</div>
                <button class="btn gold" type="submit">See available halls and price</button>
            </form>
        </section>
        <aside class="card">
            <h2>How booking works</h2>
            <div class="summary-lines">
                <div><span>1</span><b>Choose date and program</b></div>
                <div><span>2</span><b>Review an approved hall quote</b></div>
                <div><span>3</span><b>Verify email and hold the slot</b></div>
                <div><span>4</span><b>Venue verifies the deposit</b></div>
                <div class="total"><span>Confirmation</span><b>After deposit verification</b></div>
            </div>
            <p class="meta" style="margin-top:12px">Temporary holds are released automatically if email verification or deposit confirmation does not complete in time.</p>
        </aside>
    </div>
</div>
@endsection
