@php
    $photoUri = (string) ($payload['photo']['data_uri'] ?? '');
    $ganeshPath = public_path('images/report-assets/ganesh-line.png');
    $ganeshUri = is_file($ganeshPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($ganeshPath)) : '';
    $reportFontCss = \App\Support\ReportFontAssets::fontFaceCss();
    $reportFontStack = \App\Support\ReportFontAssets::cssFontStack();
    $leftSections = array_slice($payload['sections'] ?? [], 0, 4);
    $rightSections = array_slice($payload['sections'] ?? [], 4);
@endphp

<style>
{!! $reportFontCss !!}
@page { size: A4 portrait; margin: 0; }
* { box-sizing: border-box; }
.parichay-sheet {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 10mm;
    background: #fff8ed;
    color: #5f2f3f;
    font-family: {!! $reportFontStack !!};
}
.parichay-frame {
    position: relative;
    min-height: 277mm;
    border: 2.2mm solid #f28c28;
    padding: 18mm 9mm 8mm;
    background: #fffdf4;
}
.parichay-frame:before {
    content: "";
    position: absolute;
    inset: 2.6mm;
    border: 0.45mm solid #ffd18a;
}
.parichay-top-medallion {
    position: absolute;
    top: -10mm;
    left: -10mm;
    width: 48mm;
    height: 48mm;
    border-radius: 50%;
    background: #9f1d1f;
    border: 1.7mm dashed #f28c28;
    text-align: center;
    padding-top: 7mm;
    color: #fff7ed;
}
.parichay-medallion-prayer {
    font-size: 10px;
    font-weight: 800;
    margin-bottom: 1mm;
}
.parichay-medallion-ganesh {
    display: block;
    width: 17mm;
    height: 19mm;
    margin: 0 auto;
    object-fit: contain;
    filter: invert(1);
}
.parichay-title-wrap {
    text-align: center;
    margin-bottom: 7mm;
}
.parichay-title {
    display: inline-block;
    background: #e11d2f;
    color: #fff;
    font-size: 24px;
    font-weight: 900;
    padding: 2.2mm 9mm;
    transform: rotate(-1deg);
}
.parichay-header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 5mm;
}
.parichay-header-info {
    width: 67%;
    vertical-align: top;
    padding-right: 6mm;
}
.parichay-photo-cell {
    width: 33%;
    vertical-align: top;
    text-align: right;
}
.parichay-name {
    color: #8a1544;
    font-size: 21px;
    font-weight: 900;
    margin-bottom: 2mm;
}
.parichay-headline {
    color: #6b3a4d;
    font-size: 13px;
    font-weight: 800;
    margin-bottom: 2mm;
}
.parichay-meta {
    color: #7b6070;
    font-size: 10px;
}
.parichay-photo {
    width: 35mm;
    height: 43mm;
    object-fit: cover;
    border: 1mm solid #f28c28;
}
.parichay-columns {
    width: 100%;
    border-collapse: collapse;
}
.parichay-column {
    width: 50%;
    vertical-align: top;
}
.parichay-column-left { padding-right: 4mm; }
.parichay-column-right { padding-left: 4mm; }
.parichay-section {
    break-inside: avoid;
    page-break-inside: avoid;
    margin-bottom: 3.2mm;
}
.parichay-section-title {
    text-align: center;
    color: #b91c1c;
    font-size: 15px;
    font-weight: 900;
    margin: 0 0 1.4mm;
}
.parichay-row {
    display: table;
    width: 100%;
    padding: 0.45mm 0;
}
.parichay-label,
.parichay-colon,
.parichay-value {
    display: table-cell;
    vertical-align: top;
    font-size: 12.8px;
    line-height: 1.16;
}
.parichay-label {
    width: 34%;
    color: #8a1544;
    font-weight: 900;
}
.parichay-colon {
    width: 4%;
    color: #8a1544;
    font-weight: 900;
    text-align: center;
}
.parichay-value {
    color: #6b3a4d;
    font-weight: 800;
}
.parichay-group {
    margin-top: 1.5mm;
}
.parichay-group-title {
    color: #8a1544;
    font-size: 12.8px;
    font-weight: 900;
    margin-bottom: 0.6mm;
}
.parichay-list {
    margin: 0;
    padding-left: 4mm;
}
.parichay-list li {
    color: #6b3a4d;
    font-size: 12.4px;
    font-weight: 800;
    line-height: 1.16;
    margin-bottom: 0.7mm;
}
.parichay-blessing {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 6mm;
    text-align: center;
    color: #991b1b;
    font-size: 13px;
    font-weight: 900;
}
.parichay-brand-footer {
    margin-top: 1.2mm;
    color: #7f1d1d;
    font-size: 11px;
    font-weight: 800;
    text-align: center;
}
@media screen {
    .parichay-sheet { box-shadow: 0 18px 55px rgba(15, 23, 42, 0.16); }
}
</style>

<div class="parichay-sheet">
    <div class="parichay-frame">
        <div class="parichay-top-medallion">
            <div class="parichay-medallion-prayer">{{ __('profile.biodata_export_prayer') }}</div>
            @if ($ganeshUri !== '')
                <img class="parichay-medallion-ganesh" src="{{ $ganeshUri }}" alt="Ganesh">
            @endif
        </div>

        <div class="parichay-title-wrap">
            <div class="parichay-title">{{ __('profile.biodata_export_parichay_title') }}</div>
        </div>

        <table class="parichay-header-table">
            <tr>
                <td class="parichay-header-info">
                    <div class="parichay-name">{{ $payload['title'] }}</div>
                    @if (! empty($payload['headline']))
                        <div class="parichay-headline">{{ $payload['headline'] }}</div>
                    @endif
                    <div class="parichay-meta">{{ __('profile.biodata_export_generated_on', ['date' => $payload['generated_at']]) }}</div>
                </td>
                <td class="parichay-photo-cell">
                    @if ($photoUri !== '')
                        <img class="parichay-photo" src="{{ $photoUri }}" alt="{{ $payload['photo']['alt'] ?? 'Profile photo' }}">
                    @endif
                </td>
            </tr>
        </table>

        <table class="parichay-columns">
            <tr>
                <td class="parichay-column parichay-column-left">
                    @foreach ($leftSections as $section)
                        @include('biodata.templates.partials.parichay-section', ['section' => $section])
                    @endforeach
                </td>
                <td class="parichay-column parichay-column-right">
                    @foreach ($rightSections as $section)
                        @include('biodata.templates.partials.parichay-section', ['section' => $section])
                    @endforeach
                </td>
            </tr>
        </table>

        <div class="parichay-blessing">{{ __('profile.biodata_export_blessing') }}</div>
    </div>
    @include('biodata.templates.partials.generated-footer', ['class' => 'parichay-brand-footer'])
</div>
