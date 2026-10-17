<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        // Only static, backend-owned design tokens are permitted in output.
        $palettes = [
            'silver' => ['background' => '#f3f4f6', 'foreground' => '#111827', 'accent' => '#374151'],
            'ivory' => ['background' => '#fffdf5', 'foreground' => '#312d25', 'accent' => '#70513b'],
            'midnight' => ['background' => '#111827', 'foreground' => '#f9fafb', 'accent' => '#d1d5db'],
            'sage' => ['background' => '#e5eee8', 'foreground' => '#182b21', 'accent' => '#2e5e47'],
        ];
        $branding = $snapshot['branding'];
        $palette = $palettes[$branding['themePreset']] ?? $palettes['silver'];
        $font = $branding['fontPreset'] === 'modern' ? 'system-ui, sans-serif' : 'Georgia, serif';
        // Only the configured published-domain renderer may emit image URLs;
        // direct internal server previews still use non-network placeholders.
        $mediaPageSlug = $snapshot['page']['slug'] === '/'
            ? 'home' : ltrim($snapshot['page']['slug'], '/');
        $allowedMediaPages = ['home', 'halls', 'packages', 'services', 'gallery',
            'about', 'contact', 'privacy', 'terms'];
        $mediaBase = $canonicalUrl !== null && in_array($mediaPageSlug, $allowedMediaPages, true)
            ? '/_venue-media/'.$mediaPageSlug.'/' : null;
        $searchTitle = ($snapshot['page']['seoTitle'] ?? '') !== ''
            ? $snapshot['page']['seoTitle'] : $snapshot['page']['title'].' | '.$branding['siteName'];
        $searchDescription = ($snapshot['page']['seoDescription'] ?? '') !== ''
            ? $snapshot['page']['seoDescription']
            : (($branding['seoDescription'] ?? '') !== ''
                ? $branding['seoDescription']
                : ($branding['tagline'] !== '' ? $branding['tagline'] : $branding['siteName']));
    @endphp
    <title>{{ $searchTitle }}</title>
    <meta name="description" content="{{ $searchDescription }}">
    {{-- Only a published, integrity-checked venue favicon may be exposed. --}}
    @if ($mediaBase !== null && $branding['faviconMediaId'] !== null
        && isset($snapshot['media'][$branding['faviconMediaId']]))
        <link rel="icon" type="image/webp" href="{{ $mediaBase.$branding['faviconMediaId'].'.webp' }}">
    @endif
    @if ($canonicalUrl !== null)
        <link rel="canonical" href="{{ $canonicalUrl }}">
        {{-- Only publish social preview metadata for operator-released canonical pages. --}}
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $searchTitle }}">
        <meta property="og:description" content="{{ $searchDescription }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        @if ($mediaBase !== null && $branding['logoMediaId'] !== null
            && isset($snapshot['media'][$branding['logoMediaId']]))
            {{-- Exact operator-approved host and current published WebP only. --}}
            <meta property="og:image" content="{{ 'https://'.parse_url($canonicalUrl, PHP_URL_HOST).$mediaBase.$branding['logoMediaId'].'.webp' }}">
            <meta property="og:image:type" content="image/webp">
            <meta property="og:image:alt" content="{{ $snapshot['media'][$branding['logoMediaId']]['alt'] }}">
        @endif
        <meta name="robots" content="{{ config('public_site.indexing_enabled') === true ? 'index, follow' : 'noindex, nofollow' }}">
    @else
        <meta name="robots" content="noindex, nofollow">
    @endif
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; line-height: 1.55; background: {{ $palette['background'] }}; color: {{ $palette['foreground'] }}; font-family: {{ $font }}; }
        .shell { max-width: 1140px; margin: auto; padding: 24px; }
        .site-header, .site-footer { padding: 24px 0; border-bottom: 2px solid {{ $palette['accent'] }}; }
        .site-footer { border-top: 1px solid {{ $palette['accent'] }}; border-bottom: 0; }
        .site-title { font-size: clamp(1.6rem, 4vw, 2.8rem); margin: 0; }
        .site-tagline { margin: 5px 0 0; }
        .site-navigation { display: flex; flex-wrap: wrap; gap: 12px 20px; margin-top: 20px; }
        .site-navigation a { color: inherit; font-weight: 600; text-underline-offset: 4px; }
        .site-section { margin: 24px 0; padding: 22px; border: 1px solid {{ $palette['accent'] }}; border-radius: 12px; }
        .site-section h2, .site-section h3 { margin: 0 0 10px; }
        .site-section p { overflow-wrap: anywhere; white-space: pre-line; }
        .site-section a { color: {{ $palette['foreground'] }}; text-underline-offset: 3px; }
        a:focus-visible { outline: 3px solid {{ $palette['accent'] }}; outline-offset: 4px; }
        .layout-columns { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 16px; }
        .layout-column { grid-column: span var(--desktop-span); min-width: 0; }
        .site-section img, .brand-logo { display: block; max-width: 100%; height: auto; border-radius: 10px; }
        .brand-logo { margin-top: 16px; }
        .media-placeholder { padding: 30px 15px; border: 2px dashed {{ $palette['accent'] }}; border-radius: 10px; text-align: center; }
        @@media (max-width: 640px) {
            .shell { padding: 15px; }
            .site-section { padding: 14px; }
            .layout-columns { display: flex; flex-direction: column; }
            .layout-column { order: var(--mobile-order); }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="site-header" aria-label="Venue branding">
            <p class="site-title">{{ $branding['siteName'] }}</p>
            @if ($branding['tagline'] !== '')
                <p class="site-tagline">{{ $branding['tagline'] }}</p>
            @endif
            @if ($branding['logoMediaId'] !== null)
                @if ($mediaBase !== null && isset($snapshot['media'][$branding['logoMediaId']]))
                    <img class="brand-logo"
                        src="{{ $mediaBase.$branding['logoMediaId'].'.webp' }}"
                        alt="{{ $snapshot['media'][$branding['logoMediaId']]['alt'] }}"
                        width="{{ (int) $snapshot['media'][$branding['logoMediaId']]['width'] }}"
                        height="{{ (int) $snapshot['media'][$branding['logoMediaId']]['height'] }}"
                        decoding="async">
                @else
                    <p class="media-placeholder" aria-label="Venue logo pending public asset release">Approved logo placeholder</p>
                @endif
            @endif
            @if (count($snapshot['navigation']) > 1)
                <nav class="site-navigation" aria-label="Published venue pages">
                    @foreach ($snapshot['navigation'] as $item)
                        <a href="{{ $item['href'] }}" @if ($snapshot['page']['slug'] === $item['href']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            @endif
        </header>
        <main id="main-content">
            <h1>{{ $snapshot['page']['title'] }}</h1>
            @foreach ($snapshot['page']['sections'] as $block)
                @include('site.partials.published-block', ['block' => $block, 'media' => $snapshot['media'], 'mediaBase' => $mediaBase])
            @endforeach
        </main>
        <footer class="site-footer">
            <p>{{ ($branding['footerText'] ?? '') !== '' ? $branding['footerText'] : $branding['siteName'] }}</p>
            <p>Preview rendering only. Contact information, public media and booking services require separate release approval.</p>
        </footer>
    </div>
</body>
</html>
