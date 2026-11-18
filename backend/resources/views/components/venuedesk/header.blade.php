@props(['eyebrow' => 'VenueDesk', 'title' => 'Operational workspace'])
<header class="vd-pagehead">
    <div>
        <span class="vd-eyebrow">{{ $eyebrow }}</span>
        <h1>{{ $title }}</h1>
        @if (trim($slot) !== '')
            <p>{{ $slot }}</p>
        @endif
    </div>
</header>
