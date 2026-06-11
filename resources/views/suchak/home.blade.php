<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            $siteIdentity = app(\App\Services\SiteIdentityService::class);
            $siteName = $siteIdentity->get('site_name', 'नवरी मिळे नवऱ्याला');
            $siteTagline = $siteIdentity->get('site_tagline', 'Navri Mile Navryala | Marathi Matrimony');
            $heroImage = file_exists(public_path('images/homepage/hero_1779797852.jpg'))
                ? 'images/homepage/hero_1779797852.jpg'
                : 'images/matrimonial-hero.jpg';
            $isMarathiLocale = str_starts_with((string) app()->getLocale(), 'mr');
            $fontClass = $isMarathiLocale ? 'font-devanagari' : '';
            $primaryUrl = $suchakAccount
                ? route('suchak.dashboard')
                : route('suchak.register.info');
            $primaryLabel = $suchakAccount
                ? ($isMarathiLocale ? 'Dashboard उघडा' : 'Open dashboard')
                : ($isMarathiLocale ? 'सूचक म्हणून नोंदणी करा' : 'Register as Suchak');

            $copy = $isMarathiLocale ? [
                'eyebrow' => 'Verified Suchak platform',
                'title' => 'सूचक म्हणून तुमचा विवाह-जुळवणी व्यवसाय वाढवा',
                'subtitle' => 'ग्राहकांचे biodata, follow-up, packages आणि payment records एका सुरक्षित platform वर व्यवस्थित manage करा.',
                'secondary' => 'कसे काम करते?',
                'trust' => 'Private contact details आणि direct payment details public दाखवले जात नाहीत. Suchak workflow admin-governed verification आणि platform rules नुसार चालतो.',
                'benefits_title' => 'मुख्य फायदे',
                'benefits_intro' => 'जास्त माहिती नको; Suchak ला रोजच्या कामात उपयोगी पडणारी साधने स्पष्ट दिसली पाहिजेत.',
                'business_title' => 'Business growth साठी व्यवस्थित setup',
                'business_body' => 'Existing customer work अधिक organized करा, service packages नीट ठेवा आणि platform rules नुसार नवीन opportunities handle करा.',
                'process_title' => 'सरळ process',
                'tools_title' => 'Approved Suchak साठी tools',
                'final_title' => 'तुमचा Suchak business digital पद्धतीने manage करायला सुरुवात करा',
                'final_body' => 'Verified Suchak workflow मध्ये सामील व्हा आणि customer management, biodata sharing आणि follow-up अधिक व्यवस्थित करा.',
                'status' => 'Already applied? Status तपासा',
            ] : [
                'eyebrow' => 'Verified Suchak platform',
                'title' => 'Grow your matchmaking business as a Suchak',
                'subtitle' => 'Manage customer biodata, follow-ups, packages, and payment records on a secure platform.',
                'secondary' => 'How it works',
                'trust' => 'Private contact details and direct payment details are not shown publicly. Suchak workflows run under admin-governed verification and platform rules.',
                'benefits_title' => 'Core benefits',
                'benefits_intro' => 'No clutter. The page should show what helps a Suchak run daily work better.',
                'business_title' => 'A structured setup for business growth',
                'business_body' => 'Organize existing customer work, manage service packages, and handle new opportunities under platform rules.',
                'process_title' => 'Simple process',
                'tools_title' => 'Tools for approved Suchaks',
                'final_title' => 'Start managing your Suchak business digitally',
                'final_body' => 'Join the verified Suchak workflow and make customer management, biodata sharing, and follow-up more organized.',
                'status' => 'Already applied? Check status',
            ];

            $benefits = $isMarathiLocale ? [
                ['title' => 'ग्राहक व्यवस्थापन सोपे', 'body' => 'Biodata, notes, follow-up आणि status एकाच ठिकाणी ठेवा.'],
                ['title' => 'Secure biodata sharing', 'body' => 'PDF/QR sharing करताना private contact details public leak होऊ नयेत.'],
                ['title' => 'Packages आणि payment records', 'body' => 'Suchak services, customer payments आणि ledger evidence व्यवस्थित नोंदवा.'],
                ['title' => 'Platform presence', 'body' => 'Verified Suchak म्हणून professional presence तयार करा.'],
            ] : [
                ['title' => 'Simpler customer management', 'body' => 'Keep biodata, notes, follow-ups, and status in one place.'],
                ['title' => 'Secure biodata sharing', 'body' => 'Use PDF/QR sharing without publicly leaking private contact details.'],
                ['title' => 'Packages and payment records', 'body' => 'Record service packages, customer payments, and ledger evidence clearly.'],
                ['title' => 'Platform presence', 'body' => 'Build a professional presence as a verified Suchak.'],
            ];

            $process = $isMarathiLocale ? [
                'नोंदणी करा',
                'Mobile/KYC verify करा',
                'Admin approval',
                'Customer work सुरू करा',
            ] : [
                'Register',
                'Verify mobile/KYC',
                'Admin approval',
                'Start customer work',
            ];

            $tools = $isMarathiLocale ? [
                'Dashboard',
                'Customer Biodata Entry',
                'Secure PDF/QR Sharing',
                'Follow-up / CRM',
                'Payment Records',
                'Masked Search',
            ] : [
                'Dashboard',
                'Customer Biodata Entry',
                'Secure PDF/QR Sharing',
                'Follow-up / CRM',
                'Payment Records',
                'Masked Search',
            ];
        @endphp

        <title>{{ $copy['title'] }} - {{ $siteName }}</title>
        @include('layouts.partials.site-identity-head')

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700,800&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --suchak-red: #b91c1c;
                --suchak-red-dark: #7f1d1d;
                --suchak-ink: #211817;
            }
            .font-devanagari { font-family: 'Noto Sans Devanagari', 'Instrument Sans', sans-serif; }
            .suchak-page { background: #fff7f3; color: var(--suchak-ink); }
            .suchak-language {
                position: fixed;
                right: 1rem;
                top: 1rem;
                z-index: 40;
                max-width: calc(100vw - 2rem);
            }
            .suchak-primary {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: .375rem;
                background: var(--suchak-red);
                color: #fff;
                font-size: .875rem;
                font-weight: 700;
                padding: .75rem 1.5rem;
                box-shadow: 0 16px 32px rgba(127, 29, 29, .20);
                transition: background-color .15s ease, transform .15s ease;
            }
            .suchak-primary:hover { background: var(--suchak-red-dark); }
            .suchak-hero {
                position: relative;
                min-height: 86vh;
                overflow: hidden;
                background: #2d1412;
            }
            .suchak-hero::before {
                content: "";
                position: absolute;
                inset: 0;
                background-image:
                    linear-gradient(90deg, rgba(255, 248, 244, .98) 0%, rgba(255, 248, 244, .90) 33%, rgba(255, 248, 244, .45) 57%, rgba(45, 20, 18, .14) 100%),
                    var(--suchak-hero-image);
                background-position: center;
                background-size: cover;
            }
            .suchak-hero::after {
                content: "";
                position: absolute;
                inset: auto 0 0 0;
                height: 7rem;
                background: linear-gradient(180deg, rgba(255,255,255,0), #fff7f3);
            }
            .suchak-hero-inner {
                position: relative;
                z-index: 10;
                display: flex;
                align-items: center;
                min-height: 84vh;
                max-width: 80rem;
                margin: 0 auto;
                padding: 5rem 1rem;
            }
            .suchak-copy { max-width: 48rem; }
            .suchak-eyebrow {
                display: inline-flex;
                border: 1px solid #fecaca;
                border-radius: 9999px;
                background: rgba(255,255,255,.82);
                color: #991b1b;
                font-size: .75rem;
                font-weight: 800;
                letter-spacing: .025em;
                line-height: 1;
                padding: .45rem .8rem;
                text-transform: uppercase;
                box-shadow: 0 1px 4px rgba(127, 29, 29, .08);
            }
            .suchak-title {
                max-width: 46rem;
                margin-top: 1.25rem;
                color: #09090b;
                font-size: 3.7rem;
                font-weight: 800;
                letter-spacing: 0;
                line-height: 1.03;
                overflow-wrap: break-word;
            }
            .suchak-subtitle {
                max-width: 42rem;
                margin-top: 1.25rem;
                color: #3f3f46;
                font-size: 1.125rem;
                font-weight: 600;
                line-height: 1.75;
            }
            .suchak-actions {
                display: flex;
                flex-wrap: wrap;
                gap: .75rem;
                margin-top: 2rem;
            }
            .suchak-secondary {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 1px solid #fecaca;
                border-radius: .375rem;
                background: rgba(255,255,255,.86);
                color: #991b1b;
                font-size: .875rem;
                font-weight: 700;
                padding: .75rem 1.5rem;
                transition: background-color .15s ease;
            }
            .suchak-secondary:hover { background: #fff; }
            .suchak-trust {
                max-width: 42rem;
                margin-top: 1.5rem;
                color: #3f3f46;
                font-size: .875rem;
                line-height: 1.85;
            }
            @media (max-width: 767px) {
                .suchak-language {
                    left: .75rem;
                    right: auto;
                    top: .65rem;
                    transform: scale(.94);
                    transform-origin: top left;
                }
                .suchak-hero {
                    min-height: 84vh;
                }
                .suchak-hero-inner {
                    min-height: 84vh;
                    padding: 4.25rem 1rem 3rem;
                }
                .suchak-copy {
                    max-width: 21.5rem;
                }
                .suchak-hero::before {
                    background-image:
                        linear-gradient(180deg, rgba(255, 248, 244, .96) 0%, rgba(255, 248, 244, .86) 48%, rgba(255, 248, 244, .22) 100%),
                        var(--suchak-hero-image);
                    background-position: 62% center;
                }
                .suchak-title {
                    max-width: 100%;
                    font-size: 1.8rem;
                    line-height: 1.2;
                }
                .suchak-subtitle {
                    max-width: 100%;
                    font-size: .98rem;
                    line-height: 1.75;
                }
                .suchak-actions { flex-direction: column; }
                .suchak-primary,
                .suchak-secondary {
                    width: 100%;
                }
                .suchak-trust {
                    max-width: 100%;
                    line-height: 1.75;
                }
            }
        </style>
    </head>
    <body class="suchak-page min-h-screen antialiased">
        <div class="suchak-language">
            <x-language-switcher :on-red="false" />
        </div>

        <main>
            <section class="suchak-hero" style="--suchak-hero-image: url('{{ asset($heroImage) }}');">
                <img src="{{ asset($heroImage) }}" alt="" class="hidden" onerror="this.closest('.suchak-hero').classList.add('bg-red-50');">
                <div class="suchak-hero-inner">
                    <div class="suchak-copy">
                        <p class="suchak-eyebrow">
                            {{ $copy['eyebrow'] }}
                        </p>
                        <h1 class="{{ $fontClass }} suchak-title">
                            {{ $copy['title'] }}
                        </h1>
                        <p class="{{ $fontClass }} suchak-subtitle">
                            {{ $copy['subtitle'] }}
                        </p>

                        <div class="suchak-actions">
                            <a href="{{ $primaryUrl }}" class="suchak-primary">
                                {{ $primaryLabel }}
                            </a>
                            <a href="#how-it-works" class="suchak-secondary">
                                {{ $copy['secondary'] }}
                            </a>
                        </div>

                        <p class="{{ $fontClass }} suchak-trust">
                            {{ $copy['trust'] }}
                        </p>
                    </div>
                </div>
            </section>

            @if (session('status') || session('info') || session('error'))
                <div class="mx-auto mt-6 max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm">
                        {{ session('status') ?: session('info') ?: session('error') }}
                    </div>
                </div>
            @endif

            <section class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:px-6 lg:grid-cols-[0.8fr_1.2fr] lg:px-8">
                <div>
                    <p class="text-sm font-bold uppercase tracking-wide text-red-700">{{ $copy['benefits_title'] }}</p>
                    <h2 class="{{ $fontClass }} mt-3 text-3xl font-extrabold text-zinc-950">
                        {{ $copy['business_title'] }}
                    </h2>
                    <p class="{{ $fontClass }} mt-4 text-sm leading-7 text-zinc-600">
                        {{ $copy['business_body'] }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($benefits as $benefit)
                        <article class="rounded-lg border border-red-100 bg-white p-5 shadow-sm">
                            <div class="mb-4 flex h-10 w-10 items-center justify-center rounded-full bg-red-50 text-red-700">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l2.25 2.25L15.75 9M12 3.75l7.5 3v5.25c0 4.47-3.06 8.43-7.5 9.5-4.44-1.07-7.5-5.03-7.5-9.5V6.75l7.5-3z" />
                                </svg>
                            </div>
                            <h3 class="{{ $fontClass }} text-base font-bold text-zinc-950">{{ $benefit['title'] }}</h3>
                            <p class="{{ $fontClass }} mt-2 text-sm leading-6 text-zinc-600">{{ $benefit['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section id="how-it-works" class="bg-white py-12">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                        <div>
                            <p class="text-sm font-bold uppercase tracking-wide text-red-700">{{ $copy['process_title'] }}</p>
                            <h2 class="{{ $fontClass }} mt-3 text-3xl font-extrabold text-zinc-950">{{ $copy['final_title'] }}</h2>
                        </div>
                        <a href="{{ $primaryUrl }}" class="suchak-primary w-fit px-5 py-2.5">
                            {{ $primaryLabel }}
                        </a>
                    </div>

                    <ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($process as $index => $step)
                            <li class="rounded-lg border border-zinc-200 bg-zinc-50 p-5">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-red-700 text-sm font-bold text-white">{{ $index + 1 }}</span>
                                <p class="{{ $fontClass }} mt-4 text-sm font-bold text-zinc-900">{{ $step }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>

            <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-zinc-950 p-6 text-white shadow-xl sm:p-8">
                    <div class="grid gap-8 lg:grid-cols-[0.85fr_1.15fr] lg:items-center">
                        <div>
                            <p class="text-sm font-bold uppercase tracking-wide text-red-200">{{ $copy['tools_title'] }}</p>
                            <h2 class="{{ $fontClass }} mt-3 text-2xl font-extrabold">{{ $copy['final_body'] }}</h2>
                            @auth
                                <a href="{{ route('suchak.register.status') }}" class="mt-5 inline-flex rounded-md border border-white/30 px-4 py-2 text-sm font-bold text-white hover:bg-white/10">
                                    {{ $copy['status'] }}
                                </a>
                            @endauth
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($tools as $tool)
                                <div class="rounded-md border border-white/10 bg-white/10 px-4 py-3 text-sm font-semibold text-white">
                                    {{ $tool }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </body>
</html>
