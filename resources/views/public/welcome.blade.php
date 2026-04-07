<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>नवरी मिळे नवऱ्याला – Navri Mile Navryala | Marathi Matrimony</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
        <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet" />
        <style>
            :root {
                --brand-red: #c41e3a;
                --brand-red-dark: #a01830;
            }
            .font-devanagari { font-family: 'Noto Sans Devanagari', 'Instrument Sans', sans-serif; }
        </style>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <style>
                /*! minimal fallback when Vite build missing */
                *,::before,::after{box-sizing:border-box} body{margin:0;font-family:system-ui,sans-serif;line-height:1.5}
            </style>
        @endif
    </head>
    <body class="min-h-screen flex flex-col bg-[#f5f5f5] dark:bg-[#111] text-[#333] dark:text-[#e5e5e5]">
        @php
            $castes = $castes ?? collect();
            $states = $states ?? collect();
            $districts = $districts ?? collect();
            $defaultCountry = $defaultCountry ?? null;
            $homepageImages = $homepageImages ?? [];
            $heroPath = ! empty($homepageImages['hero'] ?? null)
                ? $homepageImages['hero']
                : 'images/matrimonial-hero.jpg';
            $assistedPath = $homepageImages['assisted_service'] ?? null;
            $successPath = $homepageImages['success_stories'] ?? null;
        @endphp

        <header class="w-full bg-white dark:bg-[#1a1a1a] border-b border-[#ddd] dark:border-[#333] sticky top-0 z-10">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-4 flex-wrap">
                <a href="{{ url('/') }}" class="flex items-center gap-2">
                    <span class="font-devanagari text-xl font-bold text-[var(--brand-red)]">नवरी मिळे नवऱ्याला</span>
                    <span class="text-sm text-[var(--brand-red-dark)] font-medium hidden sm:inline">Navri Mile Navryala</span>
                </a>
                @if (Route::has('login'))
                    <nav class="flex items-center gap-2 sm:gap-3 flex-wrap justify-end">
                        @auth
                            <a href="{{ route('dashboard') }}" class="px-3 py-2 text-sm font-medium text-[var(--brand-red)] hover:underline">Dashboard</a>
                            @if (Route::has('matrimony.profile.wizard'))
                                <a href="{{ route('matrimony.profile.wizard') }}" class="px-3 py-2 text-sm font-medium text-[#555] dark:text-[#bbb] hover:text-[var(--brand-red)]">Profile wizard</a>
                            @endif
                        @else
                            <span class="text-sm text-[#666] dark:text-[#999] hidden sm:inline">Already a member?</span>
                            <a href="{{ route('login') }}" class="px-3 py-2 text-sm font-medium text-[var(--brand-red)] hover:underline">Login</a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="px-4 py-2 text-sm font-medium bg-[var(--brand-red)] text-white rounded hover:bg-[var(--brand-red-dark)] transition-colors">Register</a>
                            @endif
                        @endauth
                    </nav>
                @endif
            </div>
        </header>

        <section class="w-full py-4 px-4 sm:px-6 bg-white dark:bg-[#1a1a1a] border-b border-[#eee] dark:border-[#333]">
            <div class="max-w-5xl mx-auto grid sm:grid-cols-3 gap-6 text-center sm:text-left">
                <div class="flex items-start gap-3 justify-center sm:justify-start">
                    <span class="inline-flex w-9 h-9 rounded-full bg-[#fff0f0] dark:bg-[#2a1518] items-center justify-center text-[var(--brand-red)] shrink-0" aria-hidden="true">१</span>
                    <p class="text-sm text-[#444] dark:text-[#ccc]"><span class="font-semibold text-[var(--brand-red)]">Marathi-first</span> — serious matrimony for Marathi-speaking brides, grooms, and families.</p>
                </div>
                <div class="flex items-start gap-3 justify-center sm:justify-start">
                    <span class="inline-flex w-9 h-9 rounded-full bg-[#fff0f0] dark:bg-[#2a1518] items-center justify-center text-[var(--brand-red)] shrink-0" aria-hidden="true">२</span>
                    <p class="text-sm text-[#444] dark:text-[#ccc]"><span class="font-semibold text-[var(--brand-red)]">Structured profiles</span> — guided details and preferences for clearer matching.</p>
                </div>
                <div class="flex items-start gap-3 justify-center sm:justify-start">
                    <span class="inline-flex w-9 h-9 rounded-full bg-[#fff0f0] dark:bg-[#2a1518] items-center justify-center text-[var(--brand-red)] shrink-0" aria-hidden="true">३</span>
                    <p class="text-sm text-[#444] dark:text-[#ccc]"><span class="font-semibold text-[var(--brand-red)]">Governed communication</span> — connect through the platform’s safety-minded flow.</p>
                </div>
            </div>
        </section>

        <section class="w-full py-8 lg:py-12 px-4 sm:px-6 bg-[#f5f5f5] dark:bg-[#111]">
            <div class="max-w-5xl mx-auto">
                <div class="text-center mb-8">
                    <h1 class="text-2xl sm:text-3xl lg:text-4xl font-bold text-[var(--brand-red)] mb-2 font-devanagari">गंभीर विवाहसंबंधासाठी — Marathi matrimony</h1>
                    <p class="text-[#555] dark:text-[#aaa] text-base sm:text-lg max-w-2xl mx-auto">For individuals and families seeking compatible matches with preference-based search and structured profile information.</p>
                </div>

                <div class="flex flex-col-reverse lg:flex-row shadow-lg rounded-lg overflow-hidden bg-white dark:bg-[#1a1a1a] border border-[#e0e0e0] dark:border-[#333]">
                    <div class="flex-1 p-6 lg:p-10">
                        <p class="text-xs text-[var(--brand-red)] font-semibold mb-1">Search listings</p>
                        <h2 class="mb-4 font-medium text-lg text-[#1b1b18] dark:text-[#EDEDEC]">Find profiles</h2>

                        <form method="GET" action="{{ route('matrimony.profiles.index') }}" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium mb-2 dark:text-[#EDEDEC]">Age range</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" name="age_from" min="18" max="80" placeholder="From" value="{{ request('age_from') }}"
                                        class="flex-1 border border-[#e3e3e0] dark:border-[#3E3E3A] rounded px-3 py-2 bg-white dark:bg-[#161615] text-[#1b1b18] dark:text-[#EDEDEC] focus:outline-none focus:ring-2 focus:ring-[var(--brand-red)]">
                                    <span class="text-[#706f6c] dark:text-[#A1A09A]">to</span>
                                    <input type="number" name="age_to" min="18" max="80" placeholder="To" value="{{ request('age_to') }}"
                                        class="flex-1 border border-[#e3e3e0] dark:border-[#3E3E3A] rounded px-3 py-2 bg-white dark:bg-[#161615] text-[#1b1b18] dark:text-[#EDEDEC] focus:outline-none focus:ring-2 focus:ring-[var(--brand-red)]">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium mb-2 dark:text-[#EDEDEC]">Caste</label>
                                <select name="caste_id" class="w-full border border-[#e3e3e0] dark:border-[#3E3E3A] rounded px-3 py-2 bg-white dark:bg-[#161615] text-[#1b1b18] dark:text-[#EDEDEC] focus:outline-none focus:ring-2 focus:ring-[var(--brand-red)]">
                                    <option value="">Any</option>
                                    @foreach ($castes as $c)
                                        <option value="{{ $c->id }}" {{ (string) request('caste_id') === (string) $c->id ? 'selected' : '' }}>{{ $c->display_label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            @if ($defaultCountry)
                                <input type="hidden" name="country_id" value="{{ $defaultCountry->id }}">
                            @endif
                            <div>
                                <label class="block text-sm font-medium mb-2 dark:text-[#EDEDEC]" for="welcome-search-state">{{ __('search.state') }}</label>
                                <select id="welcome-search-state" name="state_id"
                                    class="w-full border border-[#e3e3e0] dark:border-[#3E3E3A] rounded px-3 py-2 bg-white dark:bg-[#161615] text-[#1b1b18] dark:text-[#EDEDEC] focus:outline-none focus:ring-2 focus:ring-[var(--brand-red)]">
                                    <option value="">{{ __('search.any') }}</option>
                                    @foreach ($states as $st)
                                        <option value="{{ $st->id }}" {{ (string) request('state_id') === (string) $st->id ? 'selected' : '' }}>{{ $st->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-2 dark:text-[#EDEDEC]" for="welcome-search-district">{{ __('search.district') }}</label>
                                <select id="welcome-search-district" name="district_id"
                                    class="w-full border border-[#e3e3e0] dark:border-[#3E3E3A] rounded px-3 py-2 bg-white dark:bg-[#161615] text-[#1b1b18] dark:text-[#EDEDEC] focus:outline-none focus:ring-2 focus:ring-[var(--brand-red)]">
                                    <option value="">{{ __('search.any') }}</option>
                                    @foreach ($districts as $d)
                                        <option value="{{ $d->id }}" {{ (string) request('district_id') === (string) $d->id ? 'selected' : '' }}>{{ $d->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <button type="submit" class="w-full px-5 py-2.5 rounded border border-[var(--brand-red)] bg-[var(--brand-red)] text-white text-sm font-medium hover:bg-[var(--brand-red-dark)] transition-colors">
                                Search profiles
                            </button>
                        </form>
                    </div>

                    <div class="lg:w-[min(100%,420px)] shrink-0 bg-[#eee] dark:bg-[#222] min-h-[240px] lg:min-h-[320px] relative">
                        <img src="{{ asset($heroPath) }}"
                            alt=""
                            class="w-full h-full object-cover min-h-[240px] lg:min-h-[320px]"
                            onerror="this.style.display='none'; this.nextElementSibling?.classList.remove('hidden');">
                        <div class="hidden absolute inset-0 flex items-center justify-center bg-[#e8e8e8] dark:bg-[#2a2a2a] text-sm text-[#666] dark:text-[#999] p-4 text-center">Homepage image unavailable</div>
                    </div>
                </div>

                <div class="mt-8 flex flex-wrap gap-3 justify-center">
                    <a href="{{ route('matrimony.profiles.index') }}" class="inline-flex px-5 py-2.5 rounded border border-[var(--brand-red)] text-[var(--brand-red)] text-sm font-medium hover:bg-[#fff5f5] dark:hover:bg-[#2a1518] transition-colors">Browse all profiles</a>
                    @guest
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="inline-flex px-5 py-2.5 rounded bg-[var(--brand-red)] text-white text-sm font-medium hover:bg-[var(--brand-red-dark)] transition-colors">Create an account</a>
                        @endif
                    @endguest
                </div>
            </div>
        </section>

        <section class="w-full py-10 px-4 sm:px-6 bg-white dark:bg-[#161616] border-y border-[#eee] dark:border-[#333]">
            <div class="max-w-5xl mx-auto">
                <h2 class="text-xl font-bold text-[var(--brand-red)] mb-6 text-center font-devanagari">Why this platform</h2>
                <div class="grid sm:grid-cols-2 gap-6">
                    <div class="rounded-lg border border-[#e5e5e5] dark:border-[#333] p-5 bg-[#fafafa] dark:bg-[#1a1a1a]">
                        <h3 class="font-semibold text-[#1b1b18] dark:text-[#eee] mb-2">Structured profile creation</h3>
                        <p class="text-sm text-[#555] dark:text-[#aaa]">Capture education, preferences, and family context in organized sections instead of scattered notes.</p>
                    </div>
                    <div class="rounded-lg border border-[#e5e5e5] dark:border-[#333] p-5 bg-[#fafafa] dark:bg-[#1a1a1a]">
                        <h3 class="font-semibold text-[#1b1b18] dark:text-[#eee] mb-2">Preference-based search</h3>
                        <p class="text-sm text-[#555] dark:text-[#aaa]">Filter listings using the same fields members maintain on their profiles (where enabled for search).</p>
                    </div>
                    <div class="rounded-lg border border-[#e5e5e5] dark:border-[#333] p-5 bg-[#fafafa] dark:bg-[#1a1a1a]">
                        <h3 class="font-semibold text-[#1b1b18] dark:text-[#eee] mb-2">Trust and verification</h3>
                        <p class="text-sm text-[#555] dark:text-[#aaa]">Verification and profile-governance tools are available to keep listings serious; availability depends on your account and admin settings.</p>
                    </div>
                    <div class="rounded-lg border border-[#e5e5e5] dark:border-[#333] p-5 bg-[#fafafa] dark:bg-[#1a1a1a]">
                        <h3 class="font-semibold text-[#1b1b18] dark:text-[#eee] mb-2">Family-friendly</h3>
                        <p class="text-sm text-[#555] dark:text-[#aaa]">Parents and relatives can participate in account setup and review matches with a respectful, matrimony-focused tone.</p>
                    </div>
                </div>
            </div>
        </section>

        @if (! empty($assistedPath))
            <section class="w-full py-8 px-4 sm:px-6 bg-[#f5f5f5] dark:bg-[#111]">
                <div class="max-w-5xl mx-auto flex flex-col md:flex-row gap-8 items-center">
                    <div class="md:w-1/2 shrink-0">
                        <img src="{{ asset($assistedPath) }}" alt="" class="w-full max-h-64 object-contain rounded-lg bg-white dark:bg-[#1a1a1a] p-2">
                    </div>
                    <div class="md:w-1/2">
                        <h2 class="text-lg font-bold text-[var(--brand-red)] mb-2 font-devanagari">Clear, serious listings</h2>
                        <p class="text-sm text-[#555] dark:text-[#aaa]">Admin-configured visuals help highlight that this service is oriented toward matrimony — not casual dating. Images are managed from the admin panel.</p>
                    </div>
                </div>
            </section>
        @endif

        <section class="w-full py-10 px-4 sm:px-6 bg-[#fafafa] dark:bg-[#141414]">
            <div class="max-w-5xl mx-auto">
                <h2 class="text-xl font-bold text-[var(--brand-red)] mb-8 text-center font-devanagari">How it works</h2>
                <ol class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6 list-decimal list-inside text-sm text-[#444] dark:text-[#ccc]">
                    <li class="rounded-lg bg-white dark:bg-[#1a1a1a] border border-[#e5e5e5] dark:border-[#333] p-4"><span class="font-semibold text-[#1b1b18] dark:text-[#eee]">Register</span> — create your member account.</li>
                    <li class="rounded-lg bg-white dark:bg-[#1a1a1a] border border-[#e5e5e5] dark:border-[#333] p-4"><span class="font-semibold text-[#1b1b18] dark:text-[#eee]">Complete your profile</span> — add details and partner preferences in the wizard.</li>
                    <li class="rounded-lg bg-white dark:bg-[#1a1a1a] border border-[#e5e5e5] dark:border-[#333] p-4"><span class="font-semibold text-[#1b1b18] dark:text-[#eee]">Search or express interest</span> — use filters aligned with profile fields.</li>
                    <li class="rounded-lg bg-white dark:bg-[#1a1a1a] border border-[#e5e5e5] dark:border-[#333] p-4"><span class="font-semibold text-[#1b1b18] dark:text-[#eee]">Connect safely</span> — follow the platform chat and contact rules.</li>
                </ol>
            </div>
        </section>

        <section class="w-full py-10 px-4 sm:px-6 bg-white dark:bg-[#161616] border-t border-[#eee] dark:border-[#333]">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-xl font-bold text-[var(--brand-red)] mb-3 font-devanagari">पालकांसाठी — For parents &amp; families</h2>
                <p class="text-sm text-[#555] dark:text-[#aaa] leading-relaxed">You can help create the profile, review matches together, and keep conversations within the platform’s guided flow. The experience is built for respectful, marriage-oriented discussions — not social-style messaging.</p>
            </div>
        </section>

        @if (! empty($successPath))
            <section class="w-full py-8 px-4 sm:px-6 bg-[#f0f0f0] dark:bg-[#0f0f0f]">
                <div class="max-w-5xl mx-auto text-center">
                    <img src="{{ asset($successPath) }}" alt="" class="mx-auto max-h-56 object-contain rounded-lg mb-4">
                    <p class="text-sm text-[#555] dark:text-[#aaa]">Serious matrimony for Marathi-speaking communities — community imagery may be updated by administrators.</p>
                </div>
            </section>
        @endif

        <section class="w-full py-12 px-4 sm:px-6 bg-[var(--brand-red)] text-white">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-xl font-bold mb-3 font-devanagari">Ready to explore matches?</h2>
                <p class="text-sm text-white/90 mb-6">Open the profile directory with the same filters you use after signing in.</p>
                <a href="{{ route('matrimony.profiles.index') }}" class="inline-flex px-6 py-3 rounded bg-white text-[var(--brand-red)] text-sm font-semibold hover:bg-[#fff5f5] transition-colors">Go to partner search</a>
            </div>
        </section>

        <footer class="w-full mt-auto py-10 px-4 sm:px-6 bg-[#1a1a1a] text-[#bbb] text-sm">
            <div class="max-w-5xl mx-auto flex flex-col sm:flex-row justify-between gap-8">
                <div>
                    <p class="font-devanagari text-white font-semibold mb-2">नवरी मिळे नवऱ्याला</p>
                    <p class="text-xs text-[#888] max-w-md">This platform is intended solely for matrimonial matchmaking. We do not guarantee any particular outcome; members are responsible for their own verification and decisions.</p>
                </div>
                <div class="flex flex-col gap-2">
                    <span class="text-[#888] text-xs uppercase tracking-wide">Navigate</span>
                    <a href="{{ route('matrimony.profiles.index') }}" class="text-white hover:underline">Partner search</a>
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="text-white hover:underline">Login</a>
                    @endif
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="text-white hover:underline">Register</a>
                    @endif
                    @auth
                        <a href="{{ route('dashboard') }}" class="text-white hover:underline">Dashboard</a>
                    @endauth
                </div>
            </div>
            <div class="max-w-5xl mx-auto mt-8 pt-6 border-t border-[#333] text-xs text-[#777]">
                &copy; {{ date('Y') }} Navri Mile Navryala. All rights reserved.
            </div>
        </footer>

        @if (($states ?? collect())->isNotEmpty())
            <script>
                (function () {
                    var stateEl = document.getElementById('welcome-search-state');
                    var distEl = document.getElementById('welcome-search-district');
                    if (!stateEl || !distEl) return;
                    var apiDistricts = @json(url('/api/internal/location/districts'));
                    var anyLabel = @json(__('search.any'));
                    stateEl.addEventListener('change', function () {
                        var sid = stateEl.value;
                        distEl.innerHTML = '<option value="">' + anyLabel + '</option>';
                        if (!sid) return;
                        fetch(apiDistricts + '?state_id=' + encodeURIComponent(sid), {
                            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        })
                            .then(function (r) {
                                return r.json();
                            })
                            .then(function (j) {
                                var rows = j && j.data ? j.data : [];
                                rows.forEach(function (d) {
                                    var o = document.createElement('option');
                                    o.value = d.id;
                                    o.textContent = d.name;
                                    distEl.appendChild(o);
                                });
                            })
                            .catch(function () {});
                    });
                })();
            </script>
        @endif
    </body>
</html>
