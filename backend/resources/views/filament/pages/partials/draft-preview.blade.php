@php
    $previewWidth = ['desktop' => 1000, 'tablet' => 760, 'phone' => 360][$previewMode] ?? 1000;
@endphp
<div class="mx-auto w-full rounded-lg border p-4" style="max-width: {{ $previewWidth }}px" aria-label="{{ ucfirst($previewMode) }} layout preview">
    @forelse ($sections as $section)
        @php
            $type = is_array($section) ? ($section['type'] ?? '') : '';
            $data = is_array($section['data'] ?? null) ? $section['data'] : [];
            $title = is_string($data['title'] ?? null) ? $data['title'] : '';
            $body = is_string($data['body'] ?? null) ? $data['body'] : (is_string($data['subtitle'] ?? null) ? $data['subtitle'] : '');
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
                                        $childData = is_array($child['data'] ?? null) ? $child['data'] : [];
                                        $childTitle = is_string($childData['title'] ?? null) ? $childData['title'] : '';
                                        $childBody = is_string($childData['body'] ?? null) ? $childData['body'] : '';
                                    @endphp
                                    <p class="font-semibold">{{ $childTitle }}</p>
                                    <p class="text-sm">{{ $childBody }}</p>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @elseif (in_array($type, ['hero', 'text', 'cta', 'faq'], true))
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
