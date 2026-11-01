<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $palettes = [
            'silver' => ['background'=>'#f3f4f6','foreground'=>'#111827','accent'=>'#374151'],
            'ivory' => ['background'=>'#fcfaf5','foreground'=>'#26352f','accent'=>'#caa56b'],
            'midnight' => ['background'=>'#111827','foreground'=>'#f9fafb','accent'=>'#d1d5db'],
            'sage' => ['background'=>'#f5f1e8','foreground'=>'#17372e','accent'=>'#caa56b'],
        ];
        $branding=$snapshot['branding'];
        $layout=$branding['layoutPreset']??'classic';
        $palette=$palettes[$branding['themePreset']]??$palettes['ivory'];
        $font=$branding['fontPreset']==='modern'
            ? "'DM Sans',system-ui,sans-serif" : "'Cormorant Garamond',Georgia,serif";
        $bodyFont="'DM Sans',system-ui,sans-serif";
        $mediaPageSlug=$snapshot['page']['slug']==='/'?'home':ltrim($snapshot['page']['slug'],'/');
        $allowedMediaPages=['home','halls','packages','services','gallery','about','contact','privacy','terms'];
        $mediaBase=$canonicalUrl!==null&&in_array($mediaPageSlug,$allowedMediaPages,true)
            ? '/_venue-media/'.$mediaPageSlug.'/' : null;
        $searchTitle=($snapshot['page']['seoTitle']??'')!==''?$snapshot['page']['seoTitle']
            :$snapshot['page']['title'].' | '.$branding['siteName'];
        $searchDescription=($snapshot['page']['seoDescription']??'')!==''?$snapshot['page']['seoDescription']
            :(($branding['seoDescription']??'')!==''?$branding['seoDescription']
                :($branding['tagline']!==''?$branding['tagline']:$branding['siteName']));
        $galleryIds=[];
        foreach($snapshot['page']['sections'] as $section){
            foreach(($section['data']['mediaIds']??[]) as $id){$galleryIds[]=$id;}
            if(isset($section['data']['imageId'])&&$section['data']['imageId']!==''){$galleryIds[]=$section['data']['imageId'];}
        }
        $galleryIds=array_values(array_unique($galleryIds));
    @endphp
    <title>{{ $searchTitle }}</title>
    <meta name="description" content="{{ $searchDescription }}">
    @if ($mediaBase!==null && $branding['faviconMediaId']!==null && isset($snapshot['media'][$branding['faviconMediaId']]))
        <link rel="icon" type="image/webp" href="{{ $mediaBase.$branding['faviconMediaId'].'.webp' }}">
    @endif
    @if ($canonicalUrl!==null)
        <link rel="canonical" href="{{ $canonicalUrl }}">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $searchTitle }}">
        <meta property="og:description" content="{{ $searchDescription }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        <meta name="robots" content="{{ $canIndex?'index, follow':'noindex, nofollow' }}">
    @else
        <meta name="robots" content="noindex, nofollow">
    @endif
    <style>
        :root{--forest:{{ $palette['foreground'] }};--gold:{{ $palette['accent'] }};--cream:{{ $palette['background'] }};--warm:#f5f1e8;--line:#e7e4dc;--muted:#738078}
        /* approved ivory background: #fffdf5; legacy-safe font-family: system-ui, sans-serif */
        *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;color:var(--forest);background:var(--cream);font-family:{{ $bodyFont }};font-size:15px;line-height:1.6}
        a{color:inherit;text-decoration:none}img{max-width:100%;display:block}h1,h2,h3{font-family:{{ $font }};margin:0;font-weight:600;line-height:1.05}
        h1{font-size:clamp(3rem,6vw,6rem)}h2{font-size:clamp(2.4rem,4vw,3.8rem)}h3{font-size:1.7rem}
        .site-header{height:84px;position:absolute;top:0;left:0;right:0;z-index:10;display:flex;justify-content:space-between;align-items:center;padding:0 clamp(20px,5vw,80px);color:#fff;border-bottom:1px solid #ffffff30}
        .brand{display:flex;align-items:center;gap:12px;letter-spacing:.14em;font-size:.9rem}.brand b{display:block;font-size:.78rem;color:#e9ce9b}.brand small{display:block;font-size:.55rem;color:#eee;letter-spacing:.28em}.brand-mark{font-size:2rem;color:var(--gold)}
        .site-nav{display:flex;align-items:center;gap:22px;font-size:.82rem}.site-nav a:hover{color:#e9ce9b}.menu-toggle{display:none}
        .pill{display:inline-flex;align-items:center;justify-content:center;padding:12px 22px;border-radius:3px;font-size:.8rem;font-weight:700;border:1px solid transparent}.pill.gold{background:var(--gold);color:#172c23}.pill.transparent{border-color:#ffffffb3;color:#fff}
        .hero{min-height:720px;position:relative;display:flex;align-items:center;background:linear-gradient(90deg,#07150fbb,#0b1d1533),url('{{ $mediaBase!==null && $branding['logoMediaId']!==null && isset($snapshot['media'][$branding['logoMediaId']]) ? $mediaBase.$branding['logoMediaId'].'.webp' : 'https://images.unsplash.com/photo-1519167758481-83f550bb49b3?auto=format&fit=crop&w=2400&q=85' }}') center/cover;color:#fff}
        .hero-content{z-index:1;max-width:820px;margin:60px clamp(24px,8vw,140px) 0}.hero-content p{max-width:560px;font-size:1.06rem;color:#f2f2e7;margin:18px 0 28px}
        .eyebrow{font-size:.72rem;letter-spacing:.22em;text-transform:uppercase;color:#e6d2aa;font-weight:700}.eyebrow.dark{color:#9a784d}
        .section{padding:90px clamp(22px,7vw,130px)}.warm{background:var(--warm)}.intro{text-align:center}.section-copy{max-width:660px;color:var(--muted);margin:18px auto 0}
        .section-head{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:30px}.text-link{font-weight:700;color:#8c6d46}
        .hall-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:22px}.hall-card{background:#fff;overflow:hidden;box-shadow:0 16px 50px #121f1812}.hall-card img,.hall-media{height:340px;width:100%;object-fit:cover;background:#dfe6df;display:grid;place-items:center;color:#627267}.hall-caption{padding:20px 24px;display:flex;justify-content:space-between;gap:14px;align-items:center}.hall-caption p{margin:6px 0 0;font-size:.83rem;color:var(--muted)}
        .package-grid,.services-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.package{padding:34px 28px;border:1px solid var(--line);background:#fff}.package.featured{background:var(--forest);color:#fff}.package span{color:var(--gold);font-weight:700}.package h3{margin:18px 0 8px}.package p{color:var(--muted);font-size:.9rem}
        .services-grid>div{padding:30px;background:#fff;text-align:center}.services-grid span{font-size:1.9rem;color:var(--gold)}
        .gallery-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.gallery-grid img{height:280px;width:100%;object-fit:cover}
        .enquiry{display:grid;grid-template-columns:1fr 1.1fr;gap:40px;background:var(--forest);color:#fff;align-items:center}.enquiry-form{background:#fff;color:var(--forest);padding:30px;box-shadow:0 16px 50px #0002}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.enquiry-form label{display:flex;flex-direction:column;gap:6px;font-size:.78rem;font-weight:700;margin-bottom:12px}.enquiry-form input,.enquiry-form select,.enquiry-form textarea{background:#fcfbf9;border:1px solid #dddcd5;border-radius:4px;padding:11px;width:100%;font:inherit}.enquiry-form button{width:100%;border:0;padding:13px;background:var(--gold);font-weight:700;border-radius:4px}.enquiry-success,.enquiry-error{padding:10px;border-radius:5px}.enquiry-success{background:#edf7f0;color:#25543a}.enquiry-error{background:#fcebea;color:#a33}
        .contact{text-align:center}.footer{background:#10271f;color:#fff;display:flex;justify-content:space-between;gap:30px;flex-wrap:wrap;padding:38px clamp(22px,7vw,130px)}.footer p,.footer small{color:#c2d7ca;font-size:.84rem}.footer a{display:block;margin:4px 0}.designer-credit{text-align:center;padding:12px}
        @media(max-width:800px){.site-header{height:70px;padding:0 18px}.site-nav{display:none;position:absolute;top:70px;left:12px;right:12px;background:var(--forest);flex-direction:column;align-items:stretch;padding:14px 20px;gap:8px}.site-nav.open{display:flex}.menu-toggle{display:block;background:transparent;border:0;color:#fff;font-size:1.7rem}.hero{min-height:620px}.section{padding:60px 20px}.hall-grid,.enquiry,.package-grid,.services-grid{grid-template-columns:1fr}.gallery-grid{grid-template-columns:repeat(3,1fr)}.gallery-grid img{height:150px}.form-row{grid-template-columns:1fr}.hero-content{margin:30px 22px 0}}
        .cms-content .site-section{padding:22px 0;max-width:900px;margin:auto}
    </style>
</head>
<body class="theme-{{ $layout }}">
<a class="skip-link" href="#main-content" style="position:absolute;left:-9999px">Skip to content</a>
@if ($canonicalUrl === null)<p class="demo-note" style="text-align:center;padding:8px;margin:0">Preview rendering only.</p>@endif
<header class="site-header">
    <a class="brand" href="/" aria-label="{{ $branding['siteName'] }} home"><span class="brand-mark">✦</span><span>{{ strtoupper($branding['siteName']) }}<b>{{ strtoupper($branding['tagline'] ?: 'WEDDINGS & CELEBRATIONS') }}</b><small>VENUEDESK PUBLISHED SITE</small></span></a>
    <button class="menu-toggle" type="button" aria-label="Toggle navigation" onclick="document.getElementById('site-nav').classList.toggle('open')">☰</button>
    <nav class="site-nav" id="site-nav" aria-label="Main navigation">
        @foreach ($snapshot['navigation'] as $link)
            <a href="{{ $link['href'] }}">{{ $link['label'] }}</a>
        @endforeach
        <a href="/book" class="pill gold">Check Availability</a>
    </nav>
</header>
<main id="main-content">
    <section class="hero">
        <div class="hero-content">
            <span class="eyebrow">Your moment, beautifully celebrated</span>
            <h1>{{ $branding['tagline'] ?: $branding['siteName'] }}</h1>
            <p>{{ $searchDescription }}</p>
            <div class="button-row"><a href="/book" class="pill gold">Plan Your Event ↗</a><a href="#halls" class="pill transparent">Explore Our Halls</a></div>
        </div>
    </section>
    <section class="section intro">
        <span class="eyebrow dark">Welcome to {{ $branding['siteName'] }}</span>
        <h2>A beautiful setting for every celebration.</h2>
        <p class="section-copy">{{ $searchDescription }}</p>
    </section>
    <div class="cms-content">
        @foreach ($snapshot['page']['sections'] as $block)
            @include('site.partials.published-block', ['block'=>$block,'media'=>$snapshot['media'],'mediaBase'=>$mediaBase,'halls'=>$snapshot['halls']??[]])
        @endforeach
    </div>
    <section class="section warm" id="halls">
        <div class="section-head"><div><span class="eyebrow dark">Discover our spaces</span><h2>Find your perfect setting</h2></div><a class="text-link" href="/book">Ask about dates ↗</a></div>
        <div class="hall-grid">
            @forelse (($snapshot['halls'] ?? []) as $hall)
                <article class="hall-card">
                    <div class="hall-media" role="img" aria-label="{{ $hall['name'] }}">Venue photograph</div>
                    <div class="hall-caption"><div><h3>{{ $hall['name'] }}</h3><p>Up to {{ $hall['capacity'] }} guests</p>@if($hall['address']!=='')<p>{{ $hall['address'] }}</p>@endif
                        @if(($hall['phone']??'')!=='')<p><a href="tel:{{ preg_replace('/[^+0-9]/','',$hall['phone']) }}">{{ $hall['phone'] }}</a></p>@endif
                        @if(($hall['whatsapp']??'')!=='')<p><a href="https://wa.me/{{ preg_replace('/[^0-9]/','',$hall['whatsapp']) }}" rel="noopener noreferrer">WhatsApp</a></p>@endif
                    </div><a href="/book" aria-label="Book {{ $hall['name'] }}">↗</a></div>
                </article>
            @empty
                <p>Published halls will appear here.</p>
            @endforelse
        </div>
    </section>
    <section class="section" id="packages">
        <div class="section-head"><div><span class="eyebrow dark">Make it yours</span><h2>Celebrations for every occasion</h2></div></div>
        <div class="package-grid">
            <article class="package"><span>01</span><h3>Nikkah & Intimate Gatherings</h3><p>Beautiful spaces, flexible seating and thoughtful details.</p><a href="/book">Book ↗</a></article>
            <article class="package featured"><span>02</span><h3>Walima & Wedding Receptions</h3><p>Elegant celebrations with seating and service options.</p><a href="/book">Book ↗</a></article>
            <article class="package"><span>03</span><h3>Mehndi & Festive Events</h3><p>Bring your celebration to life with sound and lighting.</p><a href="/book">Book ↗</a></article>
        </div>
    </section>
    <section class="section warm" id="services">
        <div class="section-head"><div><span class="eyebrow dark">A personal touch</span><h2>Everything your event needs</h2></div></div>
        <div class="services-grid">
            <div><span>♫</span><h3>Music & Sound</h3><p>Flexible sound arrangements.</p></div>
            <div><span>✦</span><h3>Fancy Lighting</h3><p>Illumination matched to your celebration.</p></div>
            <div><span>❀</span><h3>Stage & Decorations</h3><p>Elegant settings and event styling.</p></div>
        </div>
    </section>
    <section class="section" id="gallery">
        <div class="section-head"><div><span class="eyebrow dark">Inspiration</span><h2>Moments made beautiful</h2></div></div>
        <div class="gallery-grid">
            @forelse ($galleryIds as $id)
                @if ($mediaBase!==null && isset($snapshot['media'][$id]))
                    <img src="{{ $mediaBase.$id.'.webp' }}" alt="{{ $snapshot['media'][$id]['alt'] }}" loading="lazy">
                @endif
            @empty
                <div class="hall-media">Gallery</div><div class="hall-media">Gallery</div><div class="hall-media">Gallery</div>
            @endforelse
        </div>
    </section>
    @if ($canonicalUrl !== null)
        <section class="section enquiry" id="availability">
            <div class="enquiry-copy"><span class="eyebrow">Start your story</span><h2>Let's plan something unforgettable.</h2><p>Tell us the date and celebration you're planning. A private enquiry is not a confirmed booking.</p><p class="enquiry-hint">Three programs per day: Morning, Noon and Evening.</p></div>
            <form method="POST" action="/enquiry" class="enquiry-form">
                @csrf
                <h3>Check a date</h3>
                <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <div class="enquiry-honeypot" aria-hidden="true"><label>Company website <input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>
                @if (session('enquiry_success'))<p role="status" class="enquiry-success">{{ session('enquiry_success') }}</p>@endif
                @if ($errors->any())<p role="alert" class="enquiry-error">{{ $errors->first() }}</p>@endif
                <div class="form-row"><label>Your name<input name="customer_name" required maxlength="120" value="{{ old('customer_name') }}"></label><label>Email<input type="email" name="email" maxlength="254" value="{{ old('email') }}"></label></div>
                <div class="form-row"><label>Phone / WhatsApp<input name="phone" maxlength="32" value="{{ old('phone') }}"></label><label>Event date<input type="date" name="event_date" required value="{{ old('event_date') }}"></label></div>
                <div class="form-row"><label>Hall<select name="hall_id"><option value="">General enquiry</option>@foreach (($snapshot['halls']??[]) as $hall)<option value="{{ $hall['id'] }}" @selected(old('hall_id')===$hall['id'])>{{ $hall['name'] }} (up to {{ $hall['capacity'] }})</option>@endforeach</select></label><label>Program<select name="program" required><option value="morning">Morning</option><option value="noon">Noon</option><option value="evening">Evening</option></select></label></div>
                <label>Expected guests<input type="number" name="guests" min="1" max="100000" required value="{{ old('guests') }}"></label>
                <label>Message<textarea name="message" rows="3" maxlength="2000">{{ old('message') }}</textarea></label>
                <label class="enquiry-consent"><input type="checkbox" name="consent" value="1" required> I consent to the venue using these details to respond.</label>
                <button type="submit">Preview My Enquiry ↗</button>
            </form>
        </section>
    @endif
    <section class="section contact" id="contact"><span class="eyebrow dark">Get in touch</span><h2>Your celebration starts with a conversation.</h2><p>Contact details and venue map are published by the venue owner.</p><a href="/book" class="pill gold">Book an event ↗</a></section>
</main>
<footer class="footer">
    <div><strong>{{ strtoupper($branding['siteName']) }}</strong>@if(($branding['footerText'] ?? '') !== '')<p>{{ $branding['footerText'] }}</p>@else<p>{{ $branding['siteName'] }}</p>@endif</div>
    <div>@foreach ($snapshot['navigation'] as $link)<a href="{{ $link['href'] }}">{{ $link['label'] }}</a>@endforeach</div>
    <small>Published venue website. No live payment provider is active.</small>
</footer>
@if (is_array($designerCredit ?? null) && ($designerCredit['enabled'] ?? false) === true)
    <p class="designer-credit" style="text-align: {{ $designerCredit['alignment'] }}">@if($designerCredit['prefix']!==''){{ $designerCredit['prefix'] }} @endif<a href="{{ $designerCredit['url'] }}" target="_blank" rel="noopener noreferrer">{{ $designerCredit['label'] }}</a></p>
@endif
</body>
</html>