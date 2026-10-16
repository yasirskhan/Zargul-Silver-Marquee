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
                        @include('site.partials.published-block', ['block' => $child, 'media' => $media])
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
            <div role="img" class="media-placeholder" aria-label="{{ $media[$data['imageId']]['alt'] ?? 'Approved venue photograph' }}">
                Approved image placeholder. The public image delivery route is not enabled.
            </div>
        @endif
    </section>
@elseif ($type === 'image')
    <figure class="site-section">
        <div role="img" class="media-placeholder" aria-label="{{ $data['alt'] ?? ($media[$data['imageId'] ?? '']['alt'] ?? 'Approved venue photograph') }}">
            Approved image placeholder. The public image delivery route is not enabled.
        </div>
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
            <div role="img" class="media-placeholder" aria-label="{{ $media[$id]['alt'] ?? 'Approved venue photograph' }}">Approved gallery image placeholder</div>
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
