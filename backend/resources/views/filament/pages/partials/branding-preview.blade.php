@php
    // Preview tokens are selected from server-owned palettes, never arbitrary CSS from Livewire.
    $palettes = [
        'silver' => ['background' => '#f3f4f6', 'foreground' => '#111827', 'accent' => '#374151'],
        'ivory' => ['background' => '#fffdf5', 'foreground' => '#312d25', 'accent' => '#70513b'],
        'midnight' => ['background' => '#111827', 'foreground' => '#f9fafb', 'accent' => '#d1d5db'],
        'sage' => ['background' => '#e5eee8', 'foreground' => '#182b21', 'accent' => '#2e5e47'],
    ];
    $palette = $palettes[$branding['themePreset'] ?? 'silver'] ?? $palettes['silver'];
    $font = ($branding['fontPreset'] ?? 'classic') === 'modern' ? 'system-ui, sans-serif' : 'Georgia, serif';
    $previewWidth = ['desktop' => 1000, 'tablet' => 760, 'phone' => 360][$previewMode] ?? 1000;
    $name = is_string($branding['siteName'] ?? null) ? $branding['siteName'] : '';
    $tagline = is_string($branding['tagline'] ?? null) ? $branding['tagline'] : '';
    $hasLogo = is_string($branding['logoMediaId'] ?? null) && $branding['logoMediaId'] !== '';
    $hasFavicon = is_string($branding['faviconMediaId'] ?? null) && $branding['faviconMediaId'] !== '';
@endphp
<div aria-label="Private branding draft preview" class="space-y-2">
    <p class="text-xs">Private appearance preview only. Unsaved changes and staged images are not public, and no image bytes or private asset URLs are loaded.</p>
    <div class="mx-auto w-full overflow-hidden rounded-xl border"
        style="max-width: {{ $previewWidth }}px; background-color: {{ $palette['background'] }}; color: {{ $palette['foreground'] }}; font-family: {{ $font }};">
        <div class="p-4 sm:p-6" style="border-bottom: 2px solid {{ $palette['accent'] }};">
            <div class="flex flex-wrap items-center gap-3">
                <div class="rounded border px-3 py-2 text-xs" aria-label="Logo placeholder">
                    {{ $hasLogo ? 'Logo selected (private placeholder)' : 'Logo preview placeholder' }}
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="break-words text-xl font-bold">{{ $name !== '' ? $name : 'Your venue name' }}</h3>
                    <p class="mt-1 break-words text-sm">{{ $tagline !== '' ? $tagline : 'Your venue tagline' }}</p>
                </div>
            </div>
            <div class="mt-5 rounded-lg border p-4">
                <p class="text-sm font-semibold" style="color: {{ $palette['accent'] }};">Sample venue welcome section</p>
                <p class="mt-2 text-sm">This sample illustrates the chosen colors and typography. It is not your published website.</p>
            </div>
            <p class="mt-3 text-xs">{{ $hasFavicon ? 'Favicon selected (private placeholder)' : 'No favicon selected' }}</p>
        </div>
    </div>
</div>
