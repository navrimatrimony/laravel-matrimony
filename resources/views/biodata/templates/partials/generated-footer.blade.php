@php
    $footerClass = trim((string) ($class ?? 'biodata-generated-footer'));
    $siteUrl = 'https://navrimilenavryala.com';
    $siteLabel = parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl;
    $siteLink = '<a href="'.e($siteUrl).'" style="color: inherit; text-decoration: none; font-weight: 900;">'.e($siteLabel).'</a>';
@endphp

<div class="{{ $footerClass }}">
    {!! __('profile.biodata_export_brand_footer', ['site' => $siteLink]) !!}
</div>
