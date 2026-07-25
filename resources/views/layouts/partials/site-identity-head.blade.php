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

{{-- Page-level Open Graph / Twitter overrides: a child view may define the
     `og_title` / `og_description` / `og_image` sections to replace the site-wide
     defaults (e.g. a per-request payment page publishing its own UPI QR as the
     share-preview image). With no section defined, the defaults below are used
     unchanged. --}}
@hasSection('og_title')
    <meta property="og:title" content="@yield('og_title')">
    <meta name="twitter:title" content="@yield('og_title')">
@else
    <meta property="og:title" content="{{ trim($siteNameHead.' '.$siteTaglineHead) }}">
@endif

@hasSection('og_description')
    <meta name="description" content="@yield('og_description')">
    <meta property="og:description" content="@yield('og_description')">
    <meta name="twitter:description" content="@yield('og_description')">
@elseif ($siteTaglineHead !== '')
    <meta name="description" content="{{ $siteTaglineHead }}">
    <meta property="og:description" content="{{ $siteTaglineHead }}">
@endif

@hasSection('og_image')
    <meta property="og:image" content="@yield('og_image')">
    <meta name="twitter:image" content="@yield('og_image')">
    <meta name="twitter:card" content="summary_large_image">
@elseif ($seoImageHead)
    <meta property="og:image" content="{{ $seoImageHead }}">
    <meta name="twitter:card" content="summary_large_image">
@endif
