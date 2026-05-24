@php
    $siteIdentityHead = app(\App\Services\SiteIdentityService::class);
    $siteNameHead = $siteIdentityHead->get('site_name', config('app.name', 'Laravel'));
    $siteTaglineHead = $siteIdentityHead->get('site_tagline', '');
    $faviconHead = $siteIdentityHead->assetUrl('favicon');
    $seoImageHead = $siteIdentityHead->assetUrl('default_seo_image');
@endphp

@if ($faviconHead)
    <link rel="icon" href="{{ $faviconHead }}">
@endif
<meta property="og:site_name" content="{{ $siteNameHead }}">
<meta property="og:title" content="{{ trim($siteNameHead.' '.$siteTaglineHead) }}">
@if ($siteTaglineHead !== '')
    <meta name="description" content="{{ $siteTaglineHead }}">
    <meta property="og:description" content="{{ $siteTaglineHead }}">
@endif
@if ($seoImageHead)
    <meta property="og:image" content="{{ $seoImageHead }}">
    <meta name="twitter:card" content="summary_large_image">
@endif
