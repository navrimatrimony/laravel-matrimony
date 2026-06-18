<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="googlebot" content="noindex, nofollow">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:type" content="profile">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $ogImageUrl }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $ogImageUrl }}">
    <style>
        :root {
            --brand: #9f1239;
            --brand-dark: #881337;
            --paper: #fff8f6;
            --ink: #1f2937;
            --muted: #667085;
            --border: #f1d8d3;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(180deg, #fff 0%, var(--paper) 100%);
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .page {
            width: min(100%, 520px);
            margin: 0 auto;
            padding: 20px;
        }

        .brand {
            margin: 8px 0 18px;
            text-align: center;
            color: var(--brand);
            font-size: 18px;
            font-weight: 800;
        }

        .card {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 18px 50px rgba(136, 19, 55, 0.16);
        }

        .photo {
            position: relative;
            min-height: 360px;
            background: #f7e8e4;
        }

        .photo img {
            display: block;
            width: 100%;
            height: 420px;
            object-fit: cover;
            object-position: center top;
        }

        .photo::after {
            content: "";
            position: absolute;
            inset: 42% 0 0;
            background: linear-gradient(180deg, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.74) 100%);
        }

        .hero {
            position: absolute;
            z-index: 1;
            left: 22px;
            right: 22px;
            bottom: 22px;
            color: #fff;
        }

        .hero h1 {
            margin: 0;
            font-size: 30px;
            line-height: 1.1;
            letter-spacing: 0;
        }

        .hero p {
            margin: 10px 0 0;
            color: rgba(255, 255, 255, 0.92);
            font-size: 15px;
            line-height: 1.5;
        }

        .content {
            padding: 24px;
        }

        .section-title {
            margin: 0 0 10px;
            color: #111827;
            font-size: 19px;
            font-weight: 800;
        }

        .about {
            margin: 0 0 20px;
            color: #4b5563;
            font-size: 15px;
            line-height: 1.7;
        }

        .facts {
            display: grid;
            gap: 10px;
            margin: 0 0 24px;
        }

        .fact {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            border: 1px solid #f2e6e3;
            border-radius: 14px;
            padding: 13px 14px;
            background: #fffdfc;
        }

        .fact span {
            width: 96px;
            flex: 0 0 auto;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }

        .fact strong {
            min-width: 0;
            color: #111827;
            font-size: 15px;
            line-height: 1.35;
        }

        .cta {
            display: block;
            width: 100%;
            border-radius: 14px;
            background: var(--brand);
            padding: 14px 18px;
            color: #fff;
            text-align: center;
            text-decoration: none;
            font-weight: 800;
        }

        .cta:hover {
            background: var(--brand-dark);
        }

        .note {
            margin: 14px 0 0;
            color: var(--muted);
            text-align: center;
            font-size: 12px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    @php
        $name = trim((string) ($hero['name'] ?? 'Profile'));
        $age = $hero['age'] ?? null;
        $titleLine = is_numeric($age) ? $name.', '.(int) $age : $name;
        $heroLine = implode(' • ', array_values(array_filter([
            $hero['height_label'] ?? null,
            $hero['community_label'] ?? null,
            $hero['occupation_label'] ?? null,
            $hero['location_label'] ?? null,
        ])));
        $aboutBody = trim((string) ($about['body'] ?? ''));
        $facts = array_filter([
            ['label' => 'Age', 'value' => $hero['age_label'] ?? null],
            ['label' => 'Height', 'value' => $hero['height_label'] ?? null],
            ['label' => 'Community', 'value' => $hero['community_label'] ?? null],
            ['label' => 'Education', 'value' => $profile->highest_education ?? null],
            ['label' => 'Occupation', 'value' => $hero['occupation_label'] ?? null],
            ['label' => 'Location', 'value' => $hero['location_label'] ?? null],
        ], fn ($row) => trim((string) ($row['value'] ?? '')) !== '');
    @endphp

    <main class="page">
        <div class="brand">{{ $siteName }}</div>

        <article class="card">
            <div class="photo">
                <img src="{{ $ogImageUrl }}" alt="{{ $name }}">
                <div class="hero">
                    <h1>{{ $titleLine }}</h1>
                    @if ($heroLine !== '')
                        <p>{{ $heroLine }}</p>
                    @endif
                </div>
            </div>

            <div class="content">
                @if ($aboutBody !== '')
                    <h2 class="section-title">About {{ $name }}</h2>
                    <p class="about">{{ $aboutBody }}</p>
                @endif

                @if (! empty($facts))
                    <div class="facts">
                        @foreach ($facts as $fact)
                            <div class="fact">
                                <span>{{ $fact['label'] }}</span>
                                <strong>{{ $fact['value'] }}</strong>
                            </div>
                        @endforeach
                    </div>
                @endif

                <a class="cta" href="{{ $profileUrl }}">View profile on {{ $siteName }}</a>
                <p class="note">Contact details and private information are visible only inside the platform according to profile privacy rules.</p>
            </div>
        </article>
    </main>
</body>
</html>
