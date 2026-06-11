@php
    $photoUri = (string) ($payload['photo']['data_uri'] ?? '');
    $ganeshPath = public_path('images/report-assets/ganesh-color.png');
    $ganeshUri = is_file($ganeshPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($ganeshPath)) : '';
    $reportFontCss = \App\Support\ReportFontAssets::fontFaceCss();
    $reportFontStack = \App\Support\ReportFontAssets::cssFontStack();
    $blessingText = trim((string) __('profile.biodata_export_blessing'), '| ');
@endphp

<style>
{!! $reportFontCss !!}
@page { size: A4 landscape; margin: 0; }
* { box-sizing: border-box; }
.photo-side-sheet {
    width: 297mm;
    min-height: 210mm;
    margin: 0 auto;
    padding: 4.5mm;
    background: #fffaf0;
    color: #242018;
    font-family: {!! $reportFontStack !!};
}
.photo-side-frame {
    width: 100%;
    min-height: 201mm;
    border: 1.4mm solid #6f5a8f;
    background: #fffef8;
}
.photo-side-layout {
    width: 100%;
    border-collapse: collapse;
}
.photo-side-photo-cell {
    width: 43%;
    height: 201mm;
    vertical-align: top;
    border-right: 1.2mm solid #2f2f2f;
    background: #ede7d8;
    overflow: hidden;
}
.photo-side-photo {
    display: block;
    width: 100%;
    height: 201mm;
    object-fit: cover;
}
.photo-side-photo-empty {
    height: 201mm;
    padding: 58mm 10mm 0;
    text-align: center;
    color: #7c2d12;
    font-size: 28px;
    font-weight: 900;
    background: linear-gradient(135deg, #fff7ed, #fde68a);
}
.photo-side-info-cell {
    position: relative;
    width: 57%;
    height: 201mm;
    vertical-align: top;
    padding: 4mm 7mm 8mm;
    background: #fffffb;
}
.photo-side-topline {
    display: table;
    width: 100%;
    margin-bottom: 2.2mm;
    border-bottom: 0.3mm solid #ead9a8;
    padding-bottom: 1.6mm;
}
.photo-side-ganesh-cell,
.photo-side-prayer-cell {
    display: table-cell;
    vertical-align: middle;
}
.photo-side-ganesh-cell {
    width: 15mm;
}
.photo-side-ganesh {
    width: 11mm;
    height: 13mm;
    object-fit: contain;
}
.photo-side-prayer-cell {
    color: #7f1d1d;
    font-size: 11.6px;
    font-weight: 900;
    text-align: center;
    white-space: nowrap;
}
.photo-side-title {
    width: 58mm;
    margin: 0 auto 3mm;
    background: linear-gradient(90deg, #7c3aed, #6d28d9);
    color: #fff;
    font-size: 15.5px;
    font-weight: 900;
    line-height: 1.1;
    padding: 1.6mm 2mm;
    text-align: center;
    box-shadow: 0 1mm 0 #c4b5fd;
}
.photo-side-name {
    margin: 0 0 0.8mm;
    color: #8a1544;
    font-size: 18px;
    font-weight: 900;
    text-align: center;
}
.photo-side-headline {
    margin-bottom: 1.4mm;
    color: #4b3b4e;
    font-size: 11.8px;
    font-weight: 800;
    text-align: center;
}
.photo-side-sections {
    column-count: 2;
    column-gap: 5mm;
}
.photo-side-section {
    break-inside: avoid;
    page-break-inside: avoid;
    margin: 0 0 2mm;
}
.photo-side-section-title {
    margin: 0 0 1mm;
    background: linear-gradient(90deg, #8b5cf6, #6d28d9);
    color: #fff;
    font-size: 11.8px;
    font-weight: 900;
    line-height: 1.05;
    padding: 1mm 2mm;
    text-align: center;
}
.photo-side-row {
    display: table;
    width: 100%;
    padding: 0.35mm 0;
}
.photo-side-marker,
.photo-side-label,
.photo-side-dash,
.photo-side-value {
    display: table-cell;
    vertical-align: top;
    font-size: 10.6px;
    line-height: 1.1;
}
.photo-side-marker {
    width: 3.5mm;
    color: #426b22;
    font-size: 9px;
    font-weight: 900;
}
.photo-side-label {
    width: 34%;
    color: #5b2545;
    font-weight: 900;
}
.photo-side-dash {
    width: 4%;
    color: #5b2545;
    text-align: center;
    font-weight: 900;
}
.photo-side-value {
    color: #242018;
    font-weight: 800;
}
.photo-side-group {
    break-inside: avoid;
    page-break-inside: avoid;
    margin: 1mm 0 0;
}
.photo-side-group-title {
    color: #5b2545;
    font-size: 10.8px;
    font-weight: 900;
    margin-bottom: 0.4mm;
}
.photo-side-list {
    margin: 0;
    padding-left: 4mm;
}
.photo-side-list li {
    color: #242018;
    font-size: 10.2px;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 0.45mm;
}
.photo-side-footer {
    position: absolute;
    left: 7mm;
    right: 7mm;
    bottom: 2.6mm;
    border-top: 0.25mm solid #ead9a8;
    padding-top: 1mm;
    color: #7f1d1d;
    font-size: 9.4px;
    font-weight: 800;
    text-align: center;
}
@media screen {
    .photo-side-sheet {
        box-shadow: 0 18px 55px rgba(15, 23, 42, 0.16);
    }
}
</style>

<div class="photo-side-sheet">
    <div class="photo-side-frame">
        <table class="photo-side-layout">
            <tr>
                <td class="photo-side-photo-cell">
                    @if ($photoUri !== '')
                        <img class="photo-side-photo" src="{{ $photoUri }}" alt="{{ $payload['photo']['alt'] ?? 'Profile photo' }}">
                    @else
                        <div class="photo-side-photo-empty">{{ $payload['title'] }}</div>
                    @endif
                </td>
                <td class="photo-side-info-cell">
                    <div class="photo-side-topline">
                        <div class="photo-side-ganesh-cell">
                            @if ($ganeshUri !== '')
                                <img class="photo-side-ganesh" src="{{ $ganeshUri }}" alt="Ganesh">
                            @endif
                        </div>
                        <div class="photo-side-prayer-cell">
                            || {{ __('profile.biodata_export_prayer') }} || &nbsp;&nbsp; || {{ $blessingText }} ||
                        </div>
                    </div>

                    <div class="photo-side-title">{{ __('profile.biodata_export_photo_side_title') }}</div>
                    <h1 class="photo-side-name">{{ $payload['title'] }}</h1>
                    @if (! empty($payload['headline']))
                        <div class="photo-side-headline">{{ $payload['headline'] }}</div>
                    @endif

                    <main class="photo-side-sections">
                        @forelse (($payload['sections'] ?? []) as $section)
                            <section class="photo-side-section">
                                <h2 class="photo-side-section-title">{{ $section['title'] }}</h2>

                                @foreach (($section['rows'] ?? []) as $row)
                                    <div class="photo-side-row">
                                        <div class="photo-side-marker">&#10021;</div>
                                        <div class="photo-side-label">{{ $row['label'] ?? '' }}</div>
                                        <div class="photo-side-dash">-</div>
                                        <div class="photo-side-value">
                                            @if (! empty($row['locked']) && trim((string) ($row['value'] ?? '')) === '')
                                                {{ __('profile.biodata_export_not_disclosed') }}
                                            @else
                                                {{ $row['value'] ?? '' }}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach

                                @foreach (($section['groups'] ?? []) as $group)
                                    <div class="photo-side-group">
                                        @if (! empty($group['heading']))
                                            <div class="photo-side-group-title">{{ $group['heading'] }}</div>
                                        @endif
                                        <ul class="photo-side-list">
                                            @foreach (($group['lines'] ?? []) as $line)
                                                <li>{{ $line }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach

                                @foreach (($section['marriage_blocks'] ?? []) as $block)
                                    <div class="photo-side-group">
                                        <div class="photo-side-group-title">{{ __('profile.biodata_export_marriage_details') }}</div>
                                        <ul class="photo-side-list">
                                            @foreach ($block as $line)
                                                <li>{{ $line }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach
                            </section>
                        @empty
                            <section class="photo-side-section">
                                <div class="photo-side-row">
                                    <div class="photo-side-value">{{ __('profile.biodata_export_empty') }}</div>
                                </div>
                            </section>
                        @endforelse
                    </main>

                    <div class="photo-side-footer">{{ __('profile.biodata_export_brand_footer') }}</div>
                </td>
            </tr>
        </table>
    </div>
</div>
