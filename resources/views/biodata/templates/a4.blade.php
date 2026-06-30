@php
    $orientation = (string) ($template['orientation'] ?? 'portrait');
    $border = (string) ($template['border'] ?? 'classic');
    $withPhoto = (bool) ($template['with_photo'] ?? false);
    $photoUri = $withPhoto ? (string) ($payload['photo']['data_uri'] ?? '') : '';
    $isLandscape = $orientation === 'landscape';
    $ganeshPath = public_path('images/report-assets/ganesh-color.png');
    $ganeshUri = is_file($ganeshPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($ganeshPath)) : '';
    $reportFontCss = \App\Support\ReportFontAssets::fontFaceCss();
    $reportFontStack = \App\Support\ReportFontAssets::cssFontStack();
@endphp

<style>
{!! $reportFontCss !!}
@page {
    size: A4 {{ $orientation }};
    margin: 0;
}
.biodata-export-sheet {
    box-sizing: border-box;
    width: {{ $isLandscape ? '297mm' : '210mm' }};
    min-height: {{ $isLandscape ? '210mm' : '297mm' }};
    margin: 0 auto;
    background: #fffdf8;
    color: #1f2937;
    font-family: {!! $reportFontStack !!};
    line-height: 1.14;
    padding: {{ $isLandscape ? '13mm' : '14mm' }};
}
.biodata-export-frame {
    min-height: {{ $isLandscape ? '184mm' : '269mm' }};
    padding: {{ $isLandscape ? '14mm 8mm 8mm' : '16mm 9mm 9mm' }};
    position: relative;
}
.biodata-export-frame.border-classic {
    border: 2px solid #b91c1c;
    box-shadow: inset 0 0 0 4px #fef3c7;
}
.biodata-export-frame.border-double {
    border: 4px double #991b1b;
    box-shadow: inset 0 0 0 2px #fbbf24;
}
.biodata-export-frame.border-royal {
    border: 3px double #854d0e;
    box-shadow: inset 0 0 0 5px #fee2e2, inset 0 0 0 8px #fef3c7;
}
.biodata-export-frame.border-simple {
    border: 1.5px solid #374151;
}
.biodata-export-header {
    border-bottom: 1px solid #f1c9a0;
    margin-bottom: 4mm;
    padding-bottom: 3mm;
}
.biodata-export-border-ganesh {
    position: absolute;
    top: {{ $isLandscape ? '-10mm' : '-11mm' }};
    left: 50%;
    transform: translateX(-50%);
    min-width: 38mm;
    padding: 0 5mm 1.2mm;
    background: #fffdf8;
    text-align: center;
    z-index: 2;
}
.biodata-export-ganesh {
    width: {{ $isLandscape ? '13mm' : '15mm' }};
    height: {{ $isLandscape ? '17mm' : '20mm' }};
    object-fit: contain;
    display: block;
    margin: 0 auto;
}
.biodata-export-prayer {
    margin-top: 0.4mm;
    color: #991b1b;
    font-size: 11.5px;
    font-weight: 800;
    white-space: nowrap;
    text-align: center;
}
.biodata-export-topline {
    color: #991b1b;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0;
    text-transform: uppercase;
}
.biodata-export-title-row {
    display: table;
    width: 100%;
}
.biodata-export-title-block,
.biodata-export-photo-block {
    display: table-cell;
    vertical-align: top;
}
.biodata-export-title-block {
    width: auto;
    padding-right: 8mm;
}
.biodata-export-name {
    margin: 1.5mm 0 0;
    color: #111827;
    font-size: {{ $isLandscape ? '30px' : '29px' }};
    font-weight: 800;
    letter-spacing: 0;
}
.biodata-export-headline {
    margin-top: 1.4mm;
    color: #4b5563;
    font-size: 16px;
}
.biodata-export-meta {
    margin-top: 2mm;
    color: #6b7280;
    font-size: 11px;
}
.biodata-export-photo-block {
    width: 36mm;
}
.biodata-export-photo {
    width: 31mm;
    height: 38mm;
    border: 2px solid #f59e0b;
    object-fit: cover;
}
.biodata-export-sections {
    column-count: {{ $isLandscape ? 3 : 2 }};
    column-gap: {{ $isLandscape ? '7mm' : '8mm' }};
}
.biodata-export-section {
    break-inside: avoid;
    page-break-inside: avoid;
    margin: 0 0 2.8mm;
}
.biodata-export-section-title {
    margin: 0 0 1.2mm;
    border-bottom: 1px solid #e5e7eb;
    color: #991b1b;
    font-size: 16px;
    font-weight: 800;
    padding-bottom: 1mm;
}
.biodata-export-row {
    display: table;
    width: 100%;
    border-bottom: 1px dotted #ead7c0;
    padding: 0.65mm 0;
}
.biodata-export-label,
.biodata-export-value {
    display: table-cell;
    vertical-align: top;
    font-size: 15px;
}
.biodata-export-label {
    width: 36%;
    color: #6b7280;
    font-weight: 700;
    padding-right: 2mm;
}
.biodata-export-value {
    color: #111827;
}
.biodata-export-group {
    margin-top: 1.3mm;
}
.biodata-export-group-title {
    color: #374151;
    font-size: 14.5px;
    font-weight: 800;
    margin-bottom: 1mm;
}
.biodata-export-list {
    margin: 0;
    padding-left: 3.5mm;
}
.biodata-export-list li {
    margin-bottom: 0.55mm;
    font-size: 14px;
}
.biodata-export-blessing {
    margin-top: 2.5mm;
    color: #991b1b;
    font-size: 15px;
    font-weight: 800;
    text-align: center;
}
.biodata-export-brand-footer {
    margin-top: 1.4mm;
    color: #7f1d1d;
    font-size: 12px;
    font-weight: 800;
    text-align: center;
}
.biodata-export-empty {
    color: #6b7280;
    font-style: italic;
}
@media screen {
    .biodata-export-sheet {
        box-shadow: 0 18px 55px rgba(15, 23, 42, 0.16);
    }
}
</style>

<div class="biodata-export-sheet">
    <div class="biodata-export-frame border-{{ $border }}">
        @if ($ganeshUri !== '')
            <div class="biodata-export-border-ganesh">
                <img class="biodata-export-ganesh" src="{{ $ganeshUri }}" alt="Ganesh">
                <div class="biodata-export-prayer">{{ __('profile.biodata_export_prayer') }}</div>
            </div>
        @endif
        <header class="biodata-export-header">
            <div class="biodata-export-title-row">
                <div class="biodata-export-title-block">
                    <div class="biodata-export-topline">{{ __('profile.biodata_export_topline') }}</div>
                    <h1 class="biodata-export-name">{{ $payload['title'] }}</h1>
                    @if (! empty($payload['headline']))
                        <div class="biodata-export-headline">{{ $payload['headline'] }}</div>
                    @endif
                    <div class="biodata-export-meta">{{ __('profile.biodata_export_generated_on', ['date' => $payload['generated_at']]) }}</div>
                </div>
                @if ($photoUri !== '')
                    <div class="biodata-export-photo-block">
                        <img class="biodata-export-photo" src="{{ $photoUri }}" alt="{{ $payload['photo']['alt'] ?? 'Profile photo' }}">
                    </div>
                @endif
            </div>
        </header>

        <main class="biodata-export-sections">
            @forelse (($payload['sections'] ?? []) as $section)
                <section class="biodata-export-section">
                    <h2 class="biodata-export-section-title">{{ $section['title'] }}</h2>

                    @foreach (($section['rows'] ?? []) as $row)
                        <div class="biodata-export-row">
                            <div class="biodata-export-label">{{ $row['label'] ?? '' }}</div>
                            <div class="biodata-export-value">
                                @if (! empty($row['locked']) && trim((string) ($row['value'] ?? '')) === '')
                                    <span class="biodata-export-empty">{{ __('profile.biodata_export_not_disclosed') }}</span>
                                @else
                                    {{ $row['value'] ?? '' }}
                                @endif
                            </div>
                        </div>
                    @endforeach

                    @foreach (($section['groups'] ?? []) as $group)
                        <div class="biodata-export-group">
                            @if (! empty($group['heading']))
                                <div class="biodata-export-group-title">{{ $group['heading'] }}</div>
                            @endif
                            <ul class="biodata-export-list">
                                @foreach (($group['lines'] ?? []) as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach

                    @foreach (($section['marriage_blocks'] ?? []) as $block)
                        <div class="biodata-export-group">
                            <div class="biodata-export-group-title">{{ __('profile.biodata_export_marriage_details') }}</div>
                            <ul class="biodata-export-list">
                                @foreach ($block as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </section>
            @empty
                <p class="biodata-export-empty">{{ __('profile.biodata_export_empty') }}</p>
            @endforelse
        </main>
        <div class="biodata-export-blessing">{{ __('profile.biodata_export_blessing') }}</div>
    </div>
    @include('biodata.templates.partials.generated-footer', ['class' => 'biodata-export-brand-footer'])
</div>
