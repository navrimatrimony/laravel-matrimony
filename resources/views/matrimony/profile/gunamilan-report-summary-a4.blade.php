@php
    $pdfMode = (bool) ($pdfMode ?? false);
    $previewMode = (bool) ($previewMode ?? false);
    $total = (float) ($result['total_points'] ?? 0);
    $max = (float) ($result['max_points'] ?? 36);
    $percent = $max > 0 ? min(100, max(0, ($total / $max) * 100)) : 0;
    $scoreBand = $explanation['score_band'] ?? ['label' => '', 'summary' => ''];
    $sections = array_values($explanation['sections'] ?? $result['sections'] ?? []);
    $generatedDate = now()->format('d/m/Y');
    $ganeshPath = public_path('images/report-assets/ganesh-line.png');
    $ganeshUri = is_file($ganeshPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($ganeshPath)) : '';
    $reportFontCss = \App\Support\ReportFontAssets::fontFaceCss();
    $reportFontStack = \App\Support\ReportFontAssets::cssFontStack();

    $profileById = static function ($id) use ($viewerProfile, $profile) {
        if ((int) ($viewerProfile?->id ?? 0) === (int) $id) {
            return $viewerProfile;
        }
        if ((int) ($profile?->id ?? 0) === (int) $id) {
            return $profile;
        }

        return null;
    };
    $groom = $profileById($result['groom_profile_id'] ?? null);
    $bride = $profileById($result['bride_profile_id'] ?? null);
    $name = static fn ($candidate): string => \App\Support\ProfileDisplayCopy::formatPersonName((string) ($candidate?->full_name ?? '')) ?: '-';
    $formatPoints = static function (float|int $value): string {
        $rounded = round((float) $value, 1);
        return fmod($rounded, 1.0) === 0.0 ? (string) (int) $rounded : number_format($rounded, 1);
    };
@endphp

<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('profile.gunamilan_report_title') }}</title>
    <style>
        {!! $reportFontCss !!}
        @page { size: A4 portrait; margin: 7mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: {{ $pdfMode ? '#ffffff' : '#f5f5f4' }};
            color: #1c1917;
            font-family: {!! $reportFontStack !!};
            font-size: 16px;
            line-height: 1.18;
        }
        .screen-shell { padding: 18px; }
        .page {
            width: 196mm;
            min-height: 283mm;
            margin: 0 auto;
            background: #fffdf8;
            padding: 0;
            overflow: hidden;
        }
        .frame {
            min-height: 268mm;
            border: 1.4mm solid #7f1d1d;
            box-shadow: inset 0 0 0 0.35mm #f1c27d;
            padding: 4mm;
            position: relative;
        }
        .header { text-align: center; padding-bottom: 2mm; border-bottom: 0; }
        .ganesh { width: 13mm; height: 16mm; object-fit: contain; display: block; margin: 0 auto 0.5mm; }
        .kicker { color: #7f1d1d; font-size: 12.5px; font-weight: 900; }
        h1 { margin: 0.8mm 0 0; color: #7f1d1d; font-size: 23px; }
        .date { margin-top: 0.7mm; color: #57534e; font-size: 12px; }
        .names { width: 100%; border-collapse: separate; border-spacing: 2mm 0; margin-top: 3mm; }
        .name-card {
            width: 50%;
            border: 0.25mm solid #f1c27d;
            background: #fffaf0;
            padding: 2.3mm;
        }
        .name-label { color: #7c2d12; font-size: 12px; font-weight: 900; text-transform: uppercase; }
        .name-value { margin-top: 1mm; color: #1c1917; font-size: 17px; font-weight: 900; }
        .score-band {
            margin-top: 4mm;
            border: 0.35mm solid #fecaca;
            background: #fff7f7;
            padding: 3mm;
            text-align: center;
        }
        .score-main { color: #7f1d1d; font-size: 32px; font-weight: 900; line-height: 1; }
        .score-label { margin-top: 1.2mm; color: #1c1917; font-size: 15px; font-weight: 900; }
        .score-summary { margin: 1mm auto 0; max-width: 145mm; color: #44403c; font-size: 12.2px; }
        .meter { margin: 2.4mm auto 0; width: 145mm; height: 3mm; background: #fee2e2; border: 0.2mm solid #fecaca; }
        .meter-fill { height: 100%; background: #991b1b; width: {{ $percent }}%; }
        .section-title {
            margin: 4mm 0 2mm;
            color: #7f1d1d;
            font-size: 16px;
            font-weight: 900;
            border-bottom: 0.25mm solid #f1c27d;
            padding-bottom: 1mm;
        }
        .koota-grid { width: 100%; border-collapse: separate; border-spacing: 1.6mm; }
        .koota-card {
            width: 50%;
            border: 0.22mm solid #ead7c0;
            background: #ffffff;
            padding: 2mm;
            vertical-align: top;
        }
        .koota-head { width: 100%; border-collapse: collapse; }
        .koota-name { color: #1c1917; font-size: 13px; font-weight: 900; }
        .koota-score { color: #7f1d1d; font-size: 13px; font-weight: 900; text-align: right; white-space: nowrap; }
        .koota-focus { margin-top: 1mm; color: #57534e; font-size: 11.2px; }
        .koota-line { margin-top: 1mm; color: #1c1917; font-size: 11.2px; font-weight: 700; }
        .observations {
            border: 0.25mm solid #f1c27d;
            background: #fffaf0;
            padding: 2.4mm;
            color: #44403c;
            font-size: 12px;
        }
        .observations ul { margin: 0; padding-left: 4mm; }
        .observations li { margin-bottom: 1mm; }
        .footer-note {
            margin-top: 2mm;
            border-top: 0.22mm solid #f1c27d;
            padding-top: 1.5mm;
            color: #57534e;
            font-size: 10px;
            text-align: center;
        }
        .brand-footer-outside {
            padding-top: 1.2mm;
            color: #7f1d1d;
            font-size: 11px;
            font-weight: 900;
            text-align: center;
        }
        @media print {
            .screen-shell { padding: 0; }
            .page { margin: 0; box-shadow: none; }
        }
        @media screen {
            .page { box-shadow: 0 18px 50px rgba(15, 23, 42, 0.18); }
        }
    </style>
</head>
<body>
<div class="{{ $pdfMode ? '' : 'screen-shell' }}">
    <div class="page">
        <div class="frame">
            <div class="header">
                @if ($ganeshUri !== '')
                    <img class="ganesh" src="{{ $ganeshUri }}" alt="Ganesh">
                @endif
                <div class="kicker">{{ __('profile.gunamilan_report_kicker') }}</div>
                <h1>{{ __('profile.gunamilan_report_title') }}</h1>
                <div class="date">{{ __('profile.gunamilan_report_date') }}: {{ $generatedDate }}</div>
            </div>

            <table class="names">
                <tr>
                    <td class="name-card">
                        <div class="name-label">{{ __('profile.gunamilan_groom') }}</div>
                        <div class="name-value">{{ $name($groom) }}</div>
                    </td>
                    <td class="name-card">
                        <div class="name-label">{{ __('profile.gunamilan_bride') }}</div>
                        <div class="name-value">{{ $name($bride) }}</div>
                    </td>
                </tr>
            </table>

            <div class="score-band">
                <div class="score-main">{{ $formatPoints($total) }} / {{ $formatPoints($max) }}</div>
                <div class="score-label">{{ $scoreBand['label'] ?? __('profile.gunamilan_total_label') }}</div>
                @if (($scoreBand['summary'] ?? '') !== '')
                    <div class="score-summary">{{ $scoreBand['summary'] }}</div>
                @endif
                <div class="meter"><div class="meter-fill"></div></div>
            </div>

            <div class="section-title">{{ __('profile.gunamilan_report_about_score') }}</div>
            <table class="koota-grid">
                @foreach (array_chunk($sections, 2) as $row)
                    <tr>
                        @foreach ($row as $section)
                            <td class="koota-card">
                                <table class="koota-head">
                                    <tr>
                                        <td class="koota-name">{{ $section['label'] ?? '' }}</td>
                                        <td class="koota-score">{{ $formatPoints((float) ($section['points'] ?? 0)) }} / {{ $formatPoints((float) ($section['max_points'] ?? 0)) }}</td>
                                    </tr>
                                </table>
                                <div class="koota-focus">{{ $section['focus'] ?? '' }}</div>
                                <div class="koota-line">{{ $section['report_line'] ?? $section['result_meaning'] ?? '' }}</div>
                            </td>
                        @endforeach
                        @if (count($row) === 1)
                            <td class="koota-card"></td>
                        @endif
                    </tr>
                @endforeach
            </table>

            <div class="section-title">{{ __('profile.gunamilan_report_observations') }}</div>
            <div class="observations">
                <ul>
                    @forelse (($explanation['observations'] ?? []) as $observation)
                        <li>{{ $observation }}</li>
                    @empty
                        <li>{{ __('profile.gunamilan_no_major_dosha_note') }}</li>
                    @endforelse
                    <li>{{ __('profile.gunamilan_minimum_tip') }}</li>
                </ul>
            </div>

            <div class="footer-note">
                <div>{{ __('profile.gunamilan_report_footer_note') }}</div>
            </div>
        </div>
        <div class="brand-footer-outside">{{ __('profile.gunamilan_report_brand_footer') }}</div>
    </div>
</div>
@if (! $pdfMode && ! $previewMode)
    <script>
        window.addEventListener('load', function () {
            if (window.location.pathname.endsWith('/print')) {
                window.print();
            }
        });
    </script>
@endif
</body>
</html>
