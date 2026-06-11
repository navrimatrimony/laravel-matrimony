@php
    $pdfMode = (bool) ($pdfMode ?? false);
    $previewMode = (bool) ($previewMode ?? false);
    $total = (float) ($result['total_points'] ?? 0);
    $max = (float) ($result['max_points'] ?? 36);
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
    $label = static function ($value): string {
        if (! $value) {
            return '';
        }
        if (app()->getLocale() === 'mr') {
            $mr = trim((string) ($value->label_mr ?? ''));
            if ($mr !== '') {
                return $mr;
            }
        }

        return trim((string) ($value->label ?? $value->name ?? $value->key ?? ''));
    };
    $dateValue = static function ($candidate): string {
        $raw = trim((string) ($candidate?->date_of_birth ?? ''));
        if ($raw === '') {
            return '';
        }
        try {
            return \Illuminate\Support\Carbon::parse($raw)->format('d/m/Y');
        } catch (\Throwable) {
            return $raw;
        }
    };
    $birthPlace = static function ($candidate): string {
        if (! $candidate) {
            return '';
        }
        $line = trim((string) $candidate->birthLocationDisplayLine());
        if ($line !== '') {
            return $line;
        }

        return trim((string) ($candidate->birth_place_text ?? ''));
    };
    $mangal = static fn ($candidate): string => $label($candidate?->horoscope?->mangalDoshType);
    $doshaText = static function (?array $section): string {
        if (! $section || ! empty($section['missing'])) {
            return __('profile.gunamilan_dosha_unknown');
        }

        return ((float) ($section['points'] ?? 0) <= 0.0)
            ? __('profile.gunamilan_dosha_present')
            : __('profile.gunamilan_dosha_absent');
    };
    $sectionByKey = collect($sections)->keyBy('key');
    $nadiDosha = $doshaText($sectionByKey->get('nadi'));
    $bhakootDosha = $doshaText($sectionByKey->get('bhakoot'));
    $mangalGroom = $mangal($groom);
    $mangalBride = $mangal($bride);

    $detailRows = array_values(array_filter([
        ['label' => __('profile.full_name'), 'groom' => $name($groom), 'bride' => $name($bride), 'always' => true],
        ['label' => __('profile.date_of_birth'), 'groom' => $dateValue($groom), 'bride' => $dateValue($bride)],
        ['label' => __('profile.birth_time'), 'groom' => trim((string) ($groom?->birth_time ?? '')), 'bride' => trim((string) ($bride?->birth_time ?? ''))],
        ['label' => __('profile.birth_place'), 'groom' => $birthPlace($groom), 'bride' => $birthPlace($bride)],
        ['label' => __('profile.gunamilan_detail_rashi'), 'groom' => $label($groom?->horoscope?->rashi), 'bride' => $label($bride?->horoscope?->rashi)],
        ['label' => __('profile.gunamilan_detail_nakshatra'), 'groom' => $label($groom?->horoscope?->nakshatra), 'bride' => $label($bride?->horoscope?->nakshatra)],
        ['label' => __('profile.gunamilan_detail_charan'), 'groom' => trim((string) ($groom?->horoscope?->charan ?? '')), 'bride' => trim((string) ($bride?->horoscope?->charan ?? ''))],
        ['label' => __('profile.gunamilan_detail_gan'), 'groom' => $label($groom?->horoscope?->gan), 'bride' => $label($bride?->horoscope?->gan)],
        ['label' => __('profile.gunamilan_detail_nadi'), 'groom' => $label($groom?->horoscope?->nadi), 'bride' => $label($bride?->horoscope?->nadi)],
    ], static fn (array $row): bool => ! empty($row['always']) || trim((string) $row['groom']) !== '' || trim((string) $row['bride']) !== ''));

    $hasMangal = $mangalGroom !== '' || $mangalBride !== '';
    $doshaCards = [];
    if ($hasMangal) {
        $doshaCards[] = [
            'label' => __('profile.gunamilan_mangal_dosha'),
            'lines' => [
                __('profile.gunamilan_groom').': '.($mangalGroom !== '' ? $mangalGroom : '-'),
                __('profile.gunamilan_bride').': '.($mangalBride !== '' ? $mangalBride : '-'),
            ],
        ];
    }
    $doshaCards[] = [
        'label' => __('profile.gunamilan_nadi_dosha'),
        'lines' => [$nadiDosha],
    ];
    $doshaCards[] = [
        'label' => __('profile.gunamilan_bhakoot_dosha'),
        'lines' => [$bhakootDosha],
    ];
    $doshaCards[] = [
        'label' => __('profile.gunamilan_report_observations'),
        'lines' => [implode(' ', array_slice($explanation['observations'] ?? [], 0, 2)) ?: __('profile.gunamilan_no_major_dosha_note')],
    ];
    $finalRemark = ($scoreBand['summary'] ?? '') !== ''
        ? $scoreBand['summary']
        : __('profile.gunamilan_report_footer_note');
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
            line-height: 1.16;
        }
        .screen-shell { padding: 18px; }
        .page {
            width: 196mm;
            min-height: 283mm;
            margin: 0 auto;
            background: #fffdf7;
            padding: 0;
            overflow: hidden;
        }
        .frame {
            min-height: 268mm;
            border: 2.2mm double #7f1d1d;
            box-shadow: inset 0 0 0 0.35mm #d97706;
            padding: 4mm;
            position: relative;
        }
        .top-layout {
            width: 100%;
            border-collapse: separate;
            border-spacing: 2.5mm 0;
            margin-bottom: 2.4mm;
        }
        .top-cell {
            vertical-align: top;
            border: 0;
            background: transparent;
            padding: 0;
        }
        .details-cell { width: 60%; }
        .side-cell { width: 40%; }
        .header {
            text-align: center;
            border: 0;
            background: transparent;
            padding: 0.8mm 2mm 1.2mm;
            margin-bottom: 2.4mm;
        }
        .ganesh { width: 11mm; height: 14mm; object-fit: contain; display: block; margin: 0 auto 0.5mm; }
        .kicker { color: #7f1d1d; font-size: 12.5px; font-weight: 900; }
        h1 { color: #7f1d1d; font-size: 22px; margin: 0.8mm 0 0.6mm; }
        .date { color: #57534e; font-size: 12px; }
        .info-panel {
            min-height: 75mm;
            border: 0.25mm solid #f3d39c;
            background: #fffaf0;
            padding: 2mm;
        }
        .block-title {
            margin: 2.2mm 0 1.2mm;
            color: #7f1d1d;
            font-size: 16px;
            font-weight: 900;
            border-bottom: 0.25mm solid #f3d39c;
            padding-bottom: 0.8mm;
        }
        .details-cell .block-title,
        .side-cell .block-title {
            margin-top: 0;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 0.22mm solid #e6c99c; padding: 1mm 1.2mm; vertical-align: top; }
        th { background: #fff1d6; color: #7c2d12; font-size: 13px; font-weight: 900; }
        td { background: rgba(255,255,255,0.78); }
        .details td:first-child { width: 27%; color: #44403c; font-weight: 900; }
        .details td:nth-child(2), .details td:nth-child(3) { width: 36.5%; }
        .details td { font-size: 13px; }
        .score-note {
            margin: 0.9mm 0 1.3mm;
            color: #57534e;
            font-size: 13.8px;
        }
        .score-table th:nth-child(1), .score-table td:nth-child(1) { width: 8%; text-align: center; }
        .score-table th:nth-child(3), .score-table td:nth-child(3),
        .score-table th:nth-child(4), .score-table td:nth-child(4) { width: 14%; text-align: center; }
        .score-table td:nth-child(2) { font-weight: 900; color: #1c1917; }
        .score-table th {
            font-size: 15.5px;
            padding: 1.45mm 1.35mm;
        }
        .score-table td {
            font-size: 15.1px;
            padding: 1.45mm 1.35mm;
        }
        .score-table td:nth-child(5) { font-size: 13.8px; color: #44403c; }
        .total-row td {
            background: #fff1f2;
            color: #7f1d1d;
            font-weight: 900;
            font-size: 15.4px;
        }
        .tip {
            margin-top: 1.2mm;
            border-left: 1mm solid #d97706;
            background: #fff7ed;
            padding: 1.2mm 1.6mm;
            color: #44403c;
            font-size: 13.5px;
            font-weight: 700;
        }
        .dosha-grid { width: 100%; border-collapse: separate; border-spacing: 0 1.2mm; }
        .dosha-card {
            border: 0.22mm solid #e6c99c;
            background: #fffaf0;
            padding: 1.7mm;
        }
        .dosha-label { color: #7f1d1d; font-size: 13px; font-weight: 900; }
        .dosha-value { margin-top: 0.6mm; color: #1c1917; font-size: 12.5px; }
        .verdict {
            border: 0.32mm solid #fecaca;
            background: #fff7f7;
            padding: 2mm;
            font-size: 13.6px;
        }
        .verdict p { margin: 0.5mm 0; }
        .verdict strong { color: #7f1d1d; }
        .footer-note {
            margin-top: 1.5mm;
            border-top: 0.22mm solid #e7b565;
            padding-top: 1mm;
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

            <table class="top-layout">
                <tr>
                    <td class="top-cell details-cell">
                        <div class="info-panel details-panel">
                            <div class="block-title">{{ __('profile.gunamilan_basic_details') }}</div>
                            <table class="details">
                                <thead>
                                    <tr>
                                        <th>{{ __('profile.gunamilan_detail_label') }}</th>
                                        <th>{{ __('profile.gunamilan_groom') }}</th>
                                        <th>{{ __('profile.gunamilan_bride') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($detailRows as $row)
                                        <tr>
                                            <td>{{ $row['label'] }}</td>
                                            <td>{{ $row['groom'] !== '' ? $row['groom'] : '-' }}</td>
                                            <td>{{ $row['bride'] !== '' ? $row['bride'] : '-' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </td>
                    <td class="top-cell side-cell">
                        <div class="info-panel dosha-panel">
                            <div class="block-title">{{ __('profile.gunamilan_dosha_analysis') }}</div>
                            <table class="dosha-grid">
                                @foreach ($doshaCards as $card)
                                    <tr>
                                        <td class="dosha-card">
                                            <div class="dosha-label">{{ $card['label'] }}</div>
                                            @foreach ($card['lines'] as $line)
                                                <div class="dosha-value">{{ $line }}</div>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="block-title">{{ __('profile.gunamilan_score_card_title') }}</div>
            <div class="score-note">{{ __('profile.gunamilan_report_basis') }}</div>
            <table class="score-table">
                <thead>
                    <tr>
                        <th>{{ __('profile.gunamilan_score_no') }}</th>
                        <th>{{ __('profile.gunamilan_score_parameter') }}</th>
                        <th>{{ __('profile.gunamilan_score_max') }}</th>
                        <th>{{ __('profile.gunamilan_score_obtained') }}</th>
                        <th>{{ __('profile.gunamilan_score_meaning') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sections as $index => $section)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $section['label'] ?? '' }}</td>
                            <td>{{ $formatPoints((float) ($section['max_points'] ?? 0)) }}</td>
                            <td>{{ $formatPoints((float) ($section['points'] ?? 0)) }}</td>
                            <td>{{ $section['focus'] ?? $section['report_line'] ?? '' }}</td>
                        </tr>
                    @endforeach
                    <tr class="total-row">
                        <td></td>
                        <td>{{ __('profile.gunamilan_total_label') }}</td>
                        <td>{{ $formatPoints($max) }}</td>
                        <td>{{ $formatPoints($total) }} / {{ $formatPoints($max) }}</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
            <div class="tip">{{ __('profile.gunamilan_minimum_tip') }}</div>

            <div class="block-title">{{ __('profile.gunamilan_final_verdict') }}</div>
            <div class="verdict">
                <p><strong>{{ __('profile.gunamilan_total_label') }}:</strong> {{ $formatPoints($total) }} / {{ $formatPoints($max) }}</p>
                <p><strong>{{ __('profile.gunamilan_grade') }}:</strong> {{ $scoreBand['label'] ?? '' }}</p>
                <p><strong>{{ __('profile.gunamilan_astrology_remark') }}:</strong> {{ $finalRemark }}</p>
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
