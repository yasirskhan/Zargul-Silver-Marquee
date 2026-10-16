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
    @endphp
    <title>{{ $snapshot['page']['title'] }} | {{ $branding['siteName'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; line-height: 1.55; background: {{ $palette['background'] }}; color: {{ $palette['foreground'] }}; font-family: {{ $font }}; }
        .shell { max-width: 1140px; margin: auto; padding: 24px; }
        .site-header, .site-footer { padding: 24px 0; border-bottom: 2px solid {{ $palette['accent'] }}; }
        .site-footer { border-top: 1px solid {{ $palette['accent'] }}; border-bottom: 0; }
        .site-title { font-size: clamp(1.6rem, 4vw, 2.8rem); margin: 0; }
        .site-tagline { margin: 5px 0 0; }
        .site-section { margin: 24px 0; padding: 22px; border: 1px solid {{ $palette['accent'] }}; border-radius: 12px; }
        .site-section h2, .site-section h3 { margin: 0 0 10px; }
        .site-section p { overflow-wrap: anywhere; white-space: pre-line; }
        .site-section a { color: {{ $palette['foreground'] }}; text-underline-offset: 3px; }
        a:focus-visible { outline: 3px solid {{ $palette['accent'] }}; outline-offset: 4px; }
        .layout-columns { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 16px; }
        .layout-column { grid-column: span var(--desktop-span); min-width: 0; }
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
                <p class="media-placeholder" aria-label="Venue logo pending public asset release">Approved logo placeholder</p>
            @endif
        </header>
        <main id="main-content">
            <h1>{{ $snapshot['page']['title'] }}</h1>
            @foreach ($snapshot['page']['sections'] as $block)
                @include('site.partials.published-block', ['block' => $block, 'media' => $snapshot['media']])
            @endforeach
        </main>
        <footer class="site-footer">
            <p>{{ $branding['siteName'] }}</p>
            <p>Preview rendering only. Contact information, public media and booking services require separate release approval.</p>
        </footer>
    </div>
</body>
</html>
