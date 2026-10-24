@php
    $previewWidth = ['desktop' => 1000, 'tablet' => 760, 'phone' => 360][$previewMode] ?? 1000;
    $imageStyle = static function (array $data): string {
        $aspect = in_array($data['cropAspect'] ?? '', ['16:9', '4:3', '1:1'], true)
            ? $data['cropAspect'] : null;
        $focalX = max(0, min(100, (int) ($data['focalX'] ?? 50)));
        $focalY = max(0, min(100, (int) ($data['focalY'] ?? 50)));

        return 'width:100%;'.($aspect ? 'aspect-ratio:'.$aspect.';' : '')
            .'object-fit:cover;object-position:'.$focalX.'% '.$focalY.'%;';
    };
@endphp
<div class="mx-auto w-full rounded-lg border p-4" style="max-width: {{ $previewWidth }}px" aria-label="{{ ucfirst($previewMode) }} layout preview">
    @forelse ($sections as $section)
        @php
            $type = is_array($section) ? ($section['type'] ?? '') : '';
            $data = is_array($section['data'] ?? null) ? $section['data'] : [];
            $title = is_string($data['title'] ?? null) ? $data['title'] : '';
            $body = is_string($data['body'] ?? null) ? $data['body'] : (is_string($data['subtitle'] ?? null) ? $data['subtitle'] : '');
            $imageUrl = ! empty($data['imageId']) ? $this->mediaPreviewUrl((string) $data['imageId']) : null;
        @endphp
        <article class="mb-4 rounded-lg border p-4">
            @if ($type === 'columns' && is_array($section['columns'] ?? null))
                @php
                    $columns = $section['columns'];
                    if ($previewMode === 'phone') {
                        usort($columns, fn ($a, $b) => ((int) ($a['mobileOrder'] ?? 0)) <=> ((int) ($b['mobileOrder'] ?? 0)));
                    }
                @endphp
                <div class="flex flex-wrap">
                    @foreach ($columns as $column)
                        @php
                            $width = $previewMode === 'phone' ? 100 : round(min(12, max(1, (int) ($column['widthDesktop'] ?? 12))) / 12 * 100, 3);
                        @endphp
                        <div class="box-border p-2" style="width: {{ $width }}%">
                            <div class="h-full rounded border p-3">
                                @foreach (($column['blocks'] ?? []) as $child)
                                    @php
                                        $childType = is_array($child) ? ($child['type'] ?? '') : '';
                                        $childData = is_array($child['data'] ?? null) ? $child['data'] : [];
                                        $childTitle = is_string($childData['title'] ?? null) ? $childData['title'] : '';
                                        $childBody = is_string($childData['body'] ?? null) ? $childData['body'] : '';
                                        $childImageUrl = ! empty($childData['imageId'])
                                            ? $this->mediaPreviewUrl((string) $childData['imageId']) : null;
                                    @endphp
                                    @if ($childType === 'image')
                                        @if ($childImageUrl)
                                            <img src="{{ $childImageUrl }}"
                                                alt="{{ is_string($childData['alt'] ?? null) ? $childData['alt'] : 'Venue photograph' }}"
                                                style="{{ $imageStyle($childData) }}" class="rounded border">
                                        @else
                                            <p class="text-sm">Image placeholder</p>
                                        @endif
                                        @if (! empty($childData['caption']))
                                            <p class="mt-1 text-xs">{{ $childData['caption'] }}</p>
                                        @endif
                                    @else
                                        <p class="font-semibold">{{ $childTitle }}</p>
                                        <p class="text-sm">{{ $childBody }}</p>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @elseif ($type === 'image')
                @if ($imageUrl)
                    <img src="{{ $imageUrl }}"
                        alt="{{ is_string($data['alt'] ?? null) ? $data['alt'] : 'Venue photograph' }}"
                        style="{{ $imageStyle($data) }}" class="rounded border">
                @else
                    <p class="text-sm">Image placeholder</p>
                @endif
                @if (! empty($data['caption']))
                    <p class="mt-1 text-xs">{{ $data['caption'] }}</p>
                @endif
            @elseif ($type === 'gallery' && is_array($data['mediaIds'] ?? null))
                <h3 class="font-semibold">{{ $title }}</h3>
                <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-3">
                    @foreach ($data['mediaIds'] as $mediaId)
                        @php($galleryUrl = is_string($mediaId) ? $this->mediaPreviewUrl($mediaId) : null)
                        @if ($galleryUrl)
                            <img src="{{ $galleryUrl }}" alt="Approved venue gallery image"
                                class="aspect-[4/3] w-full rounded border object-cover">
                        @endif
                    @endforeach
                </div>
            @elseif ($type === 'hero')
                @if ($imageUrl)
                    <img src="{{ $imageUrl }}"
                        alt="{{ is_string($data['alt'] ?? null) ? $data['alt'] : 'Venue photograph' }}"
                        style="{{ $imageStyle($data) }}" class="mb-2 rounded border">
                @endif
                <h3 class="font-semibold">{{ $title }}</h3>
                <p class="mt-1 text-sm">{{ $body }}</p>
            @elseif (in_array($type, ['text', 'cta', 'faq'], true))
                <h3 class="font-semibold">{{ $title }}</h3>
                <p class="mt-1 text-sm">{{ $body }}</p>
            @else
                <p class="text-sm">Preview placeholder: {{ $type }}. Approved media and live business catalog are not loaded.</p>
            @endif
        </article>
    @empty
        <p class="text-sm">Add a section to see the responsive draft layout.</p>
    @endforelse
</div>
