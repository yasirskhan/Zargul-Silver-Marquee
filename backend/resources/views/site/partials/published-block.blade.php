@php
    $type = $block['type'];
    $data = $block['data'] ?? [];
@endphp
@if ($type === 'columns')
    <section class="site-section" aria-label="Responsive venue content columns">
        <div class="layout-columns">
            @foreach ($block['columns'] as $column)
                <div class="layout-column" style="--desktop-span: {{ (int) $column['widthDesktop'] }}; --mobile-order: {{ (int) $column['mobileOrder'] }};">
                    @foreach ($column['blocks'] as $child)
                        @include('site.partials.published-block', ['block' => $child, 'media' => $media, 'mediaBase' => $mediaBase])
                    @endforeach
                </div>
            @endforeach
        </div>
    </section>
@elseif (in_array($type, ['hero', 'text', 'cta', 'faq'], true))
    <section class="site-section" aria-label="{{ ucfirst($type) }} section">
        @if (! empty($data['eyebrow']))
            <p>{{ $data['eyebrow'] }}</p>
        @endif
        @if (! empty($data['title']))
            <h2>{{ $data['title'] }}</h2>
        @endif
        @foreach (['subtitle', 'body', 'description'] as $field)
            @if (! empty($data[$field]))
                <p>{{ $data[$field] }}</p>
            @endif
        @endforeach
        @if (! empty($data['buttonLabel']) && ! empty($data['buttonHref']))
            <a href="{{ $data['buttonHref'] }}" rel="noopener noreferrer">{{ $data['buttonLabel'] }}</a>
        @endif
        @if (! empty($data['imageId']))
            @if ($mediaBase !== null && isset($media[$data['imageId']]))
                <img src="{{ $mediaBase.$data['imageId'].'.webp' }}"
                    alt="{{ $media[$data['imageId']]['alt'] }}"
                    width="{{ (int) $media[$data['imageId']]['width'] }}"
                    height="{{ (int) $media[$data['imageId']]['height'] }}"
                    loading="lazy" decoding="async">
            @else
                <div role="img" class="media-placeholder" aria-label="{{ $media[$data['imageId']]['alt'] ?? 'Approved venue photograph' }}">
                    Approved image placeholder. The public image delivery route is not enabled.
                </div>
            @endif
        @endif
    </section>
@elseif ($type === 'image')
    <figure class="site-section">
        @if ($mediaBase !== null && ! empty($data['imageId']) && isset($media[$data['imageId']]))
            <img src="{{ $mediaBase.$data['imageId'].'.webp' }}"
                alt="{{ $media[$data['imageId']]['alt'] }}"
                width="{{ (int) $media[$data['imageId']]['width'] }}"
                height="{{ (int) $media[$data['imageId']]['height'] }}"
                loading="lazy" decoding="async">
        @else
            <div role="img" class="media-placeholder" aria-label="{{ $data['alt'] ?? ($media[$data['imageId'] ?? '']['alt'] ?? 'Approved venue photograph') }}">
                Approved image placeholder. The public image delivery route is not enabled.
            </div>
        @endif
        @if (! empty($data['caption']))
            <figcaption>{{ $data['caption'] }}</figcaption>
        @endif
    </figure>
@elseif ($type === 'gallery')
    <section class="site-section" aria-label="Venue gallery">
        @if (! empty($data['title']))
            <h2>{{ $data['title'] }}</h2>
        @endif
        @foreach (($data['mediaIds'] ?? []) as $id)
            @if ($mediaBase !== null && isset($media[$id]))
                <img src="{{ $mediaBase.$id.'.webp' }}" alt="{{ $media[$id]['alt'] }}"
                    width="{{ (int) $media[$id]['width'] }}" height="{{ (int) $media[$id]['height'] }}"
                    loading="lazy" decoding="async">
            @else
                <div role="img" class="media-placeholder" aria-label="{{ $media[$id]['alt'] ?? 'Approved venue photograph' }}">Approved gallery image placeholder</div>
            @endif
        @endforeach
    </section>
@else
    <section class="site-section" aria-label="{{ ucfirst($type) }} information">
        @if (! empty($data['title']))
            <h2>{{ $data['title'] }}</h2>
        @endif
        @if (! empty($data['description']))
            <p>{{ $data['description'] }}</p>
        @endif
        <p>Live halls, packages, services or enquiry actions require approved business integration before public launch.</p>
    </section>
@endif
