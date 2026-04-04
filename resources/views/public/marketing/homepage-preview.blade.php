<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>नवरी मिळे नवऱ्याला - Marathi Matrimony Preview</title>

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    <style>
        :root {
            --brand-red: #e11d48;
            --brand-red-dark: #be123c;
            --brand-green: #15803d;
            --brand-cream: #fffaf5;
            --brand-ink: #1f2937;
            --brand-muted: #6b7280;
            --brand-border: #f3e8e3;
            --brand-gold: #f59e0b;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            color: var(--brand-ink);
            background:
                radial-gradient(circle at top left, rgba(225, 29, 72, 0.10), transparent 30%),
                radial-gradient(circle at top right, rgba(21, 128, 61, 0.08), transparent 28%),
                linear-gradient(180deg, #fffdfb 0%, #fff7f3 100%);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 20px 60px rgba(190, 24, 60, 0.08);
        }

        .soft-card {
            background: #ffffff;
            border: 1px solid #f4e7e3;
            box-shadow: 0 12px 32px rgba(17, 24, 39, 0.05);
        }

        .section-title {
            letter-spacing: -0.02em;
        }

        .hero-ring {
            position: absolute;
            inset: auto;
            border-radius: 9999px;
            filter: blur(4px);
            opacity: 0.35;
        }

        .pattern-dot {
            background-image: radial-gradient(rgba(225,29,72,.18) 1.1px, transparent 1.1px);
            background-size: 14px 14px;
        }

        .gradient-text {
            background: linear-gradient(90deg, #be123c 0%, #15803d 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
            color: white;
            box-shadow: 0 10px 24px rgba(225, 29, 72, 0.28);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(225, 29, 72, 0.34);
        }

        .btn-secondary {
            background: white;
            color: #14532d;
            border: 1px solid rgba(21, 128, 61, 0.18);
        }

        .btn-secondary:hover {
            background: #f0fdf4;
        }

        .badge {
            border: 1px solid rgba(225, 29, 72, 0.14);
            background: rgba(255, 255, 255, 0.88);
        }

        .stat-card {
            background: linear-gradient(180deg, #ffffff 0%, #fff8f6 100%);
            border: 1px solid #f6e4e0;
        }

        .story-card:hover,
        .service-card:hover,
        .feature-card:hover {
            transform: translateY(-4px);
            transition: all 0.25s ease;
        }

        .profile-chip {
            background: linear-gradient(180deg, #ffffff 0%, #fff5f6 100%);
            border: 1px solid #f8dfe5;
        }

        .marriage-line {
            background: linear-gradient(90deg, rgba(225, 29, 72, 0.1), rgba(21, 128, 61, 0.1));
            height: 1px;
        }
    </style>
</head>
<body class="min-h-screen antialiased">
    @php
        $logoPath = public_path('images/navri-logo.png');
        $logoUrl = file_exists($logoPath) ? asset('images/navri-logo.png') : null;

        $registerUrl = Route::has('register') ? route('register') : '#';
        $loginUrl = Route::has('login') ? route('login') : '#';
        $dashboardUrl = Route::has('dashboard') ? route('dashboard') : '#';
        $profileWizardUrl = Route::has('matrimony.profile.wizard') ? route('matrimony.profile.wizard') : '#';
        $profilesUrl = Route::has('matrimony.profiles.index') ? route('matrimony.profiles.index') : '#';

        $stats = [
            ['value' => '100%', 'label' => 'Mobile verified profiles'],
            ['value' => 'Safe', 'label' => 'Privacy-first browsing'],
            ['value' => 'Fast', 'label' => 'Marathi focused matching'],
        ];

        $features = [
            [
                'title' => 'मराठी कुटुंबांसाठी खास',
                'desc' => 'भाषा, संस्कार, कुटुंबाची पार्श्वभूमी आणि जुळणारी अपेक्षा लक्षात घेऊन अनुभव तयार केलेला.',
            ],
            [
                'title' => 'विश्वास आणि गोपनीयता',
                'desc' => 'निवडक visibility, प्रोफाइल कंट्रोल आणि serious intent वर भर देणारा सुरक्षित अनुभव.',
            ],
            [
                'title' => 'सुरुवात अगदी सोपी',
                'desc' => 'नोंदणी, प्रोफाइल setup आणि योग्य profiles पाहण्याची flow अगदी सोपी आणि स्पष्ट.',
            ],
        ];

        $steps = [
            ['step' => '01', 'title' => 'नोंदणी करा', 'desc' => 'Free account तयार करा आणि basic माहिती भरा.'],
            ['step' => '02', 'title' => 'प्रोफाइल पूर्ण करा', 'desc' => 'फोटो, कुटुंब, शिक्षण, अपेक्षा आणि इतर तपशील जोडा.'],
            ['step' => '03', 'title' => 'योग्य जुळणी शोधा', 'desc' => 'Search, shortlist आणि interests द्वारे पुढची पायरी सुरू करा.'],
        ];

        $stories = [
            [
                'name' => 'श्रुती & अमोल',
                'text' => 'कुटुंबाच्या अपेक्षा, शिक्षण आणि शहर या तिन्ही गोष्टींमध्ये योग्य जुळणारा profile मिळाला.',
            ],
            [
                'name' => 'पूजा & संकेत',
                'text' => 'सोप्या interface मुळे profile build आणि संपर्क प्रक्रिया खूपच सहज झाली.',
            ],
            [
                'name' => 'मृणाल & रोहित',
                'text' => 'मराठी community-focused platform असल्यामुळे filtering अधिक relevant वाटली.',
            ],
        ];

        $faq = [
            [
                'q' => 'ही preview page existing homepage replace करते का?',
                'a' => 'नाही. ही फक्त स्वतंत्र preview route वर आहे. पसंत पडली तर नंतर root route याकडे वळवता येईल.',
            ],
            [
                'q' => 'यामुळे आधीचे routes किंवा function बंद पडतील का?',
                'a' => 'नाही. हा page standalone आहे आणि existing login/register/dashboard/profile routes फक्त link म्हणून वापरतो.',
            ],
            [
                'q' => 'लोगो कसा लावायचा?',
                'a' => 'तुझा actual logo `public/images/navri-logo.png` येथे ठेवला तर header मध्ये आपोआप दिसेल.',
            ],
        ];
    @endphp

    <header class="sticky top-0 z-40 border-b border-rose-100/80 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ url('/') }}" class="flex items-center gap-3">
                @if ($logoUrl)
                    <img src="{{ $logoUrl }}" alt="Navri Mile Navryala" class="h-14 w-auto sm:h-16">
                @else
                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-2xl font-black text-rose-600">
                        न
                    </div>
                    <div>
                        <div class="text-lg font-black text-gray-900 sm:text-xl">नवरी मिळे नवऱ्याला</div>
                        <div class="text-xs font-medium uppercase tracking-[0.22em] text-green-700">Marathi Matrimony</div>
                    </div>
                @endif
            </a>

            <nav class="hidden items-center gap-6 lg:flex">
                <a href="#why-us" class="text-sm font-semibold text-gray-700 transition hover:text-rose-600">का निवडावे</a>
                <a href="#how-it-works" class="text-sm font-semibold text-gray-700 transition hover:text-rose-600">कसे काम करते</a>
                <a href="#stories" class="text-sm font-semibold text-gray-700 transition hover:text-rose-600">Success Stories</a>
                <a href="#faq" class="text-sm font-semibold text-gray-700 transition hover:text-rose-600">FAQ</a>
            </nav>

            <div class="flex items-center gap-2 sm:gap-3">
                @auth
                    <a href="{{ $dashboardUrl }}" class="rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-rose-200 hover:text-rose-600">
                        Dashboard
                    </a>
                    <a href="{{ $profilesUrl }}" class="btn-primary rounded-full px-4 py-2 text-sm font-semibold transition">
                        Profiles
                    </a>
                @else
                    @if (Route::has('login'))
                        <a href="{{ $loginUrl }}" class="rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-rose-200 hover:text-rose-600">
                            Login
                        </a>
                    @endif

                    @if (Route::has('register'))
                        <a href="{{ $registerUrl }}" class="btn-primary rounded-full px-4 py-2 text-sm font-semibold transition">
                            Free Register
                        </a>
                    @endif
                @endauth
            </div>
        </div>
    </header>

    <main>
        <section class="relative overflow-hidden">
            <div class="hero-ring left-[-120px] top-[30px] h-[260px] w-[260px] bg-rose-200"></div>
            <div class="hero-ring right-[-100px] top-[60px] h-[240px] w-[240px] bg-green-200"></div>

            <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-2 lg:gap-14 lg:px-8 lg:py-20">
                <div>
                    <div class="mb-5 flex flex-wrap gap-3">
                        <span class="badge rounded-full px-4 py-2 text-xs font-bold uppercase tracking-[0.2em] text-rose-700">
                            Marathi Matrimony Preview
                        </span>
                        <span class="badge rounded-full px-4 py-2 text-xs font-bold uppercase tracking-[0.2em] text-green-700">
                            Clean • Warm • Trust-led
                        </span>
                    </div>

                    <h1 class="section-title text-4xl font-black leading-tight text-gray-900 sm:text-5xl lg:text-6xl">
                        योग्य जोडीदार
                        <span class="gradient-text">मराठी मनांसाठी</span>
                        अधिक जवळ
                    </h1>

                    <p class="mt-5 max-w-2xl text-base leading-7 text-gray-600 sm:text-lg">
                        तुझ्या logo च्या रंगछटा आणि Marathi brand feel लक्षात घेऊन हा homepage preview design केलेला आहे.
                        उबदार, विश्वासार्ह आणि लग्न-जोडीच्या theme ला suit होईल असा premium पण simple look ठेवला आहे.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-4">
                        @auth
                            <a href="{{ $profileWizardUrl }}" class="btn-primary rounded-full px-6 py-3 text-sm font-bold transition">
                                प्रोफाइल पूर्ण करा
                            </a>
                            <a href="{{ $profilesUrl }}" class="btn-secondary rounded-full px-6 py-3 text-sm font-bold transition">
                                Profiles पहा
                            </a>
                        @else
                            @if (Route::has('register'))
                                <a href="{{ $registerUrl }}" class="btn-primary rounded-full px-6 py-3 text-sm font-bold transition">
                                    सुरुवात करा
                                </a>
                            @endif

                            @if (Route::has('login'))
                                <a href="{{ $loginUrl }}" class="btn-secondary rounded-full px-6 py-3 text-sm font-bold transition">
                                    Existing member?
                                </a>
                            @endif
                        @endauth
                    </div>

                    <div class="mt-10 grid gap-4 sm:grid-cols-3">
                        @foreach ($stats as $stat)
                            <div class="stat-card rounded-2xl p-4">
                                <div class="text-2xl font-black text-rose-600">{{ $stat['value'] }}</div>
                                <div class="mt-1 text-sm leading-6 text-gray-600">{{ $stat['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="relative">
                    <div class="glass-card relative overflow-hidden rounded-[32px] p-5 sm:p-7">
                        <div class="pattern-dot absolute inset-0 opacity-40"></div>

                        <div class="relative z-10">
                            <div class="mb-5 flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-bold uppercase tracking-[0.18em] text-rose-600">Trusted Matchmaking</p>
                                    <h2 class="mt-1 text-2xl font-black text-gray-900">तुमच्या शोधाला सुंदर सुरुवात</h2>
                                </div>
                                <div class="rounded-2xl bg-green-50 px-3 py-2 text-sm font-bold text-green-700">
                                    Marathi Focus
                                </div>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="profile-chip rounded-3xl p-4">
                                    <div class="mb-3 flex items-center justify-between">
                                        <span class="text-sm font-bold text-rose-700">Bride Profile</span>
                                        <span class="rounded-full bg-rose-100 px-2 py-1 text-xs font-bold text-rose-700">Verified</span>
                                    </div>
                                    <div class="h-28 rounded-2xl bg-gradient-to-br from-rose-100 via-orange-50 to-white"></div>
                                    <div class="mt-4 text-lg font-black text-gray-900">श्रुती, 27</div>
                                    <div class="mt-1 text-sm text-gray-600">पुणे · Engineer · Family-oriented</div>
                                </div>

                                <div class="profile-chip rounded-3xl p-4">
                                    <div class="mb-3 flex items-center justify-between">
                                        <span class="text-sm font-bold text-green-700">Groom Profile</span>
                                        <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-bold text-green-700">Active</span>
                                    </div>
                                    <div class="h-28 rounded-2xl bg-gradient-to-br from-green-100 via-emerald-50 to-white"></div>
                                    <div class="mt-4 text-lg font-black text-gray-900">अमोल, 30</div>
                                    <div class="mt-1 text-sm text-gray-600">नाशिक · IT · संस्कारी कुटुंब</div>
                                </div>
                            </div>

                            <div class="marriage-line my-6"></div>

                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="rounded-2xl bg-white/90 p-4 shadow-sm">
                                    <div class="text-xs font-bold uppercase tracking-[0.2em] text-gray-400">Matching</div>
                                    <div class="mt-2 text-xl font-black text-gray-900">92%</div>
                                </div>
                                <div class="rounded-2xl bg-white/90 p-4 shadow-sm">
                                    <div class="text-xs font-bold uppercase tracking-[0.2em] text-gray-400">Language</div>
                                    <div class="mt-2 text-xl font-black text-gray-900">मराठी</div>
                                </div>
                                <div class="rounded-2xl bg-white/90 p-4 shadow-sm">
                                    <div class="text-xs font-bold uppercase tracking-[0.2em] text-gray-400">Intent</div>
                                    <div class="mt-2 text-xl font-black text-gray-900">Serious</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="absolute -bottom-6 -left-2 hidden rounded-2xl bg-white px-4 py-3 shadow-xl sm:block">
                        <div class="text-xs font-bold uppercase tracking-[0.18em] text-gray-400">Design mood</div>
                        <div class="mt-1 text-sm font-semibold text-gray-800">Warm • Elegant • Family Friendly</div>
                    </div>
                </div>
            </div>
        </section>

        <section id="why-us" class="py-12 sm:py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="text-sm font-bold uppercase tracking-[0.25em] text-rose-600">Why this concept</p>
                    <h2 class="section-title mt-3 text-3xl font-black text-gray-900 sm:text-4xl">
                        लोगोच्या भावनेशी जुळणारा homepage
                    </h2>
                    <p class="mt-4 text-base leading-7 text-gray-600">
                        लाल, हिरवा आणि warm cream palette वापरून Marathi matrimonial brand साठी premium पण approachable identity तयार केली आहे.
                    </p>
                </div>

                <div class="mt-10 grid gap-6 md:grid-cols-3">
                    @foreach ($features as $feature)
                        <div class="feature-card soft-card rounded-3xl p-6 transition">
                            <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-xl text-rose-600">
                                ✦
                            </div>
                            <h3 class="text-xl font-black text-gray-900">{{ $feature['title'] }}</h3>
                            <p class="mt-3 text-sm leading-7 text-gray-600">{{ $feature['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="how-it-works" class="py-12 sm:py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-[36px] bg-gradient-to-br from-rose-600 via-rose-700 to-green-800 p-8 text-white sm:p-10 lg:p-12">
                    <div class="grid items-start gap-10 lg:grid-cols-[1.05fr_1fr]">
                        <div>
                            <p class="text-sm font-bold uppercase tracking-[0.24em] text-rose-100">How it works</p>
                            <h2 class="mt-3 text-3xl font-black leading-tight sm:text-4xl">
                                सोप्या 3 टप्प्यांत सुरू करा तुमचा partner search
                            </h2>
                            <p class="mt-5 max-w-2xl text-sm leading-7 text-rose-50/90 sm:text-base">
                                Existing system मधल्या register, dashboard, profile wizard आणि profile browsing flows ला धरून हा homepage CTA design केलेला आहे.
                            </p>

                            <div class="mt-8 flex flex-wrap gap-3">
                                @auth
                                    <a href="{{ $profileWizardUrl }}" class="rounded-full bg-white px-5 py-3 text-sm font-bold text-rose-700 transition hover:bg-rose-50">
                                        प्रोफाइल build करा
                                    </a>
                                    <a href="{{ $profilesUrl }}" class="rounded-full border border-white/25 px-5 py-3 text-sm font-bold text-white transition hover:bg-white/10">
                                        Search सुरू करा
                                    </a>
                                @else
                                    @if (Route::has('register'))
                                        <a href="{{ $registerUrl }}" class="rounded-full bg-white px-5 py-3 text-sm font-bold text-rose-700 transition hover:bg-rose-50">
                                            Register Free
                                        </a>
                                    @endif

                                    @if (Route::has('login'))
                                        <a href="{{ $loginUrl }}" class="rounded-full border border-white/25 px-5 py-3 text-sm font-bold text-white transition hover:bg-white/10">
                                            Login
                                        </a>
                                    @endif
                                @endauth
                            </div>
                        </div>

                        <div class="grid gap-4">
                            @foreach ($steps as $item)
                                <div class="rounded-3xl bg-white/10 p-5 ring-1 ring-white/15 backdrop-blur-sm">
                                    <div class="text-xs font-bold uppercase tracking-[0.28em] text-rose-100">{{ $item['step'] }}</div>
                                    <h3 class="mt-2 text-xl font-black">{{ $item['title'] }}</h3>
                                    <p class="mt-2 text-sm leading-7 text-rose-50/90">{{ $item['desc'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="stories" class="py-12 sm:py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-[0.24em] text-green-700">Success Stories</p>
                        <h2 class="section-title mt-2 text-3xl font-black text-gray-900 sm:text-4xl">विश्वास वाढवणाऱ्या गोष्टी</h2>
                    </div>
                    <div class="text-sm text-gray-500">Placeholder content — नंतर real stories data ने replace करता येईल.</div>
                </div>

                <div class="mt-10 grid gap-6 md:grid-cols-3">
                    @foreach ($stories as $story)
                        <div class="story-card soft-card rounded-3xl p-6 transition">
                            <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-rose-100 to-green-100 text-lg font-black text-rose-700">
                                ❤
                            </div>
                            <h3 class="text-xl font-black text-gray-900">{{ $story['name'] }}</h3>
                            <p class="mt-3 text-sm leading-7 text-gray-600">{{ $story['text'] }}</p>
                            <div class="mt-5 inline-flex rounded-full bg-rose-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-rose-700">
                                Preview Card
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="py-12 sm:py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="service-card rounded-[32px] border border-rose-100 bg-white p-8 shadow-sm transition">
                        <div class="inline-flex rounded-full bg-rose-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] text-rose-700">
                            Design direction
                        </div>
                        <h3 class="mt-4 text-2xl font-black text-gray-900">Homepage म्हणून वापरायचा असल्यास</h3>
                        <p class="mt-4 text-sm leading-7 text-gray-600">
                            Preview approve झाल्यावर root route मधील `welcome` ऐवजी हा view वापरता येईल. Existing features तशाच राहतील; फक्त public landing बदलणार.
                        </p>
                    </div>

                    <div class="service-card rounded-[32px] border border-green-100 bg-gradient-to-br from-green-50 to-white p-8 shadow-sm transition">
                        <div class="inline-flex rounded-full bg-white px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] text-green-700">
                            Easy rollback
                        </div>
                        <h3 class="mt-4 text-2xl font-black text-gray-900">नसला आवडला तरी risk नाही</h3>
                        <p class="mt-4 text-sm leading-7 text-gray-600">
                            हा page स्वतंत्र route वर असल्यामुळे न आवडल्यास file delete करून route काढला की cleanup complete.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section id="faq" class="pb-16 pt-8 sm:pb-20">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="text-sm font-bold uppercase tracking-[0.24em] text-rose-600">FAQ</p>
                    <h2 class="section-title mt-3 text-3xl font-black text-gray-900 sm:text-4xl">लहान पण महत्त्वाच्या गोष्टी</h2>
                </div>

                <div class="mt-10 space-y-4">
                    @foreach ($faq as $item)
                        <details class="soft-card rounded-2xl p-5 group">
                            <summary class="cursor-pointer list-none pr-8 text-base font-black text-gray-900">
                                {{ $item['q'] }}
                            </summary>
                            <p class="mt-3 text-sm leading-7 text-gray-600">
                                {{ $item['a'] }}
                            </p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-rose-100 bg-white/80 backdrop-blur">
        <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-8 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
            <div>
                <div class="text-lg font-black text-gray-900">नवरी मिळे नवऱ्याला</div>
                <div class="mt-1 text-sm text-gray-500">Marathi Matrimony homepage preview</div>
            </div>

            <div class="flex flex-wrap gap-3">
                @auth
                    <a href="{{ $dashboardUrl }}" class="rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:border-rose-200 hover:text-rose-600">
                        Dashboard
                    </a>
                    <a href="{{ $profilesUrl }}" class="rounded-full bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                        Search Profiles
                    </a>
                @else
                    @if (Route::has('login'))
                        <a href="{{ $loginUrl }}" class="rounded-full border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:border-rose-200 hover:text-rose-600">
                            Login
                        </a>
                    @endif

                    @if (Route::has('register'))
                        <a href="{{ $registerUrl }}" class="rounded-full bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                            Register
                        </a>
                    @endif
                @endauth
            </div>
        </div>
    </footer>
</body>
</html>
