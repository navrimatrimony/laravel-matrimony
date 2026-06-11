@php
    $orientation = (string) ($template['orientation'] ?? 'portrait');
    $border = (string) ($template['border'] ?? 'classic');
    $withPhoto = (bool) ($template['with_photo'] ?? false);
    $photoUri = $withPhoto ? (string) ($payload['photo']['data_uri'] ?? '') : '';
    $isLandscape = $orientation === 'landscape';
@endphp

<style>
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
    font-family: "DejaVu Sans", "Noto Sans Devanagari", sans-serif;
    line-height: 1.32;
    padding: {{ $isLandscape ? '13mm' : '14mm' }};
}
.biodata-export-frame {
    min-height: {{ $isLandscape ? '184mm' : '269mm' }};
    padding: {{ $isLandscape ? '8mm' : '9mm' }};
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
    margin-bottom: 7mm;
    padding-bottom: 5mm;
}
.biodata-export-topline {
    color: #991b1b;
    font-size: 10px;
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
    margin: 2mm 0 0;
    color: #111827;
    font-size: {{ $isLandscape ? '25px' : '24px' }};
    font-weight: 800;
    letter-spacing: 0;
}
.biodata-export-headline {
    margin-top: 2mm;
    color: #4b5563;
    font-size: 12px;
}
.biodata-export-meta {
    margin-top: 3mm;
    color: #6b7280;
    font-size: 9px;
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
    margin: 0 0 5mm;
}
.biodata-export-section-title {
    margin: 0 0 2mm;
    border-bottom: 1px solid #e5e7eb;
    color: #991b1b;
    font-size: 12px;
    font-weight: 800;
    padding-bottom: 1mm;
}
.biodata-export-row {
    display: table;
    width: 100%;
    border-bottom: 1px dotted #ead7c0;
    padding: 1.2mm 0;
}
.biodata-export-label,
.biodata-export-value {
    display: table-cell;
    vertical-align: top;
    font-size: 10.5px;
}
.biodata-export-label {
    width: 37%;
    color: #6b7280;
    font-weight: 700;
    padding-right: 2mm;
}
.biodata-export-value {
    color: #111827;
}
.biodata-export-group {
    margin-top: 2mm;
}
.biodata-export-group-title {
    color: #374151;
    font-size: 10.5px;
    font-weight: 800;
    margin-bottom: 1mm;
}
.biodata-export-list {
    margin: 0;
    padding-left: 4mm;
}
.biodata-export-list li {
    margin-bottom: 1mm;
    font-size: 10.2px;
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
        <header class="biodata-export-header">
            <div class="biodata-export-title-row">
                <div class="biodata-export-title-block">
                    <div class="biodata-export-topline">Biodata</div>
                    <h1 class="biodata-export-name">{{ $payload['title'] }}</h1>
                    @if (! empty($payload['headline']))
                        <div class="biodata-export-headline">{{ $payload['headline'] }}</div>
                    @endif
                    <div class="biodata-export-meta">Generated from saved profile on {{ $payload['generated_at'] }}</div>
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
                                    <span class="biodata-export-empty">Not disclosed</span>
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
                            <div class="biodata-export-group-title">Marriage details</div>
                            <ul class="biodata-export-list">
                                @foreach ($block as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </section>
            @empty
                <p class="biodata-export-empty">No profile details are available yet.</p>
            @endforelse
        </main>
    </div>
</div>
