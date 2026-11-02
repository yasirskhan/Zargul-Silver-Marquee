@props(['eyebrow' => 'VenueDesk', 'title' => 'Operational workspace'])
<section class="vd-hero">
    <span class="eyebrow">{{ $eyebrow }}</span>
    <div class="vd-hero-title" role="presentation">{{ $title }}</div>
    @if (trim($slot) !== '')
        <p>{{ $slot }}</p>
    @endif
</section>