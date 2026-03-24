<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Support\HeightDisplay;
use Carbon\Carbon;

/**
 * “About me” quick templates: composed variants; optional profile facts are included
 * sparsely and with varied wording (seeded). No names, city, company, income, or DOB.
 */
final class AboutMeQuickTemplateService
{
    private const VARIANT_COUNT = 24;

    /**
     * @return list<array{label: string, text: string}>
     */
    public function resolvedAboutTemplatesForProfile(MatrimonyProfile $profile): array
    {
        $profile->loadMissing([
            'district', 'state', 'religion', 'caste', 'profession', 'maritalStatus', 'children',
        ]);

        $labels = $this->labels();
        $pid = (int) $profile->id;
        $out = [];
        foreach ($labels as $catIdx => $label) {
            $v = $this->pickVariantIndex($pid, $catIdx, self::VARIANT_COUNT);
            $text = $this->composeVariantText($profile, $catIdx, $v);
            $out[] = ['label' => $label, 'text' => $text];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function labels(): array
    {
        $t = trans('about_me_templates.labels');
        if (is_array($t) && count($t) > 0) {
            return array_values($t);
        }

        return [
            'Simple & family-first',
            'Career with balance',
            'Tradition & open mind',
            'Honesty & respect',
            'Hobbies & leisure',
            'Learning & growth',
            'Warm home life',
            'Calm & steady',
            'Friendly & social',
            'Faith & roots',
        ];
    }

    private function composeVariantText(MatrimonyProfile $profile, int $categoryIndex, int $variantIndex): string
    {
        $pid = (int) $profile->id;
        $seed = $this->mixSeed($pid, $categoryIndex, $variantIndex);

        $themes = $this->themePrefixes();
        $intros = $this->introFragments();
        $middles = $this->middleFragments();
        $outros = $this->outroFragments();

        $theme = $themes[$categoryIndex % count($themes)] ?? '';
        $i = $variantIndex % count($intros);
        $m = ($variantIndex + $categoryIndex * 3) % count($middles);
        $o = ($variantIndex * 5 + $categoryIndex * 7) % count($outros);

        $base = trim($theme.' '.$intros[$i].' '.$middles[$m].' '.$outros[$o]);
        $base = preg_replace('/\s+/', ' ', $base) ?? $base;

        $factParts = $this->pickFactSentences($profile, $seed);
        $hobby = $this->randomHobbyLine($seed ^ 0x9E3779B9);

        $chunks = array_filter([$base, ...$factParts, $hobby]);
        $text = trim(implode(' ', $chunks));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return $text;
    }

    /**
     * @return list<string>
     */
    private function pickFactSentences(MatrimonyProfile $profile, int $seed): array
    {
        /** @var list<callable(): ?string> $builders */
        $builders = [
            fn () => $this->factDistrictState($profile, $seed),
            fn () => $this->factWorkRole($profile, $seed),
            fn () => $this->factHeight($profile, $seed),
            fn () => $this->factReligionCaste($profile, $seed),
            fn () => $this->factEducation($profile, $seed),
            fn () => $this->factAge($profile, $seed),
            fn () => $this->factMarital($profile, $seed),
            fn () => $this->factChildren($profile, $seed),
        ];

        // Pick 2–5 distinct facts (seeded); not every template includes everything.
        $count = 2 + ($seed % 4);
        $order = range(0, count($builders) - 1);
        usort($order, function (int $a, int $b) use ($seed): int {
            return crc32((string) $a.'|'.$seed) <=> crc32((string) $b.'|'.$seed);
        });

        $out = [];
        $taken = 0;
        foreach ($order as $idx) {
            if ($taken >= $count) {
                break;
            }
            $line = $builders[$idx]();
            if ($line !== null && $line !== '') {
                $out[] = $line;
                $taken++;
            }
        }

        return $out;
    }

    private function factDistrictState(MatrimonyProfile $profile, int $seed): ?string
    {
        $d = $profile->district?->name;
        $s = $profile->state?->name;
        if (($d === null || $d === '') && ($s === null || $s === '')) {
            return null;
        }
        $pair = trim(implode(', ', array_filter([(string) $d, (string) $s])));
        if ($pair === '') {
            return null;
        }

        $variants = [
            'My district and state are '.$pair.'.',
            'I am from '.$pair.' (district and state).',
            'Home region: '.$pair.', at district and state level.',
            'I belong to '.$pair.' administratively.',
        ];

        return $variants[$seed % count($variants)];
    }

    /**
     * Role / field only — never company name.
     */
    private function factWorkRole(MatrimonyProfile $profile, int $seed): ?string
    {
        $title = trim((string) ($profile->occupation_title ?? ''));
        $prof = trim((string) ($profile->profession?->name ?? ''));
        $line = $title !== '' ? $title : $prof;
        if ($line === '') {
            return null;
        }

        $variants = [
            'Professionally I work as '.$line.'.',
            'My line of work is '.$line.'.',
            'I am engaged in '.$line.'.',
            'Career-wise I identify with '.$line.'.',
        ];

        return $variants[($seed >> 3) % count($variants)];
    }

    private function factHeight(MatrimonyProfile $profile, int $seed): ?string
    {
        $cm = $profile->height_cm !== null ? (int) $profile->height_cm : 0;
        if ($cm < 1) {
            return null;
        }
        $h = HeightDisplay::formatFeetInches($cm);
        $variants = [
            'My height is about '.$h.'.',
            'I am roughly '.$h.' tall.',
            'Height-wise I am around '.$h.'.',
        ];

        return $variants[($seed >> 5) % count($variants)];
    }

    private function factReligionCaste(MatrimonyProfile $profile, int $seed): ?string
    {
        $rel = trim((string) ($profile->religion?->display_label ?? ''));
        $caste = trim((string) ($profile->caste?->display_label ?? ''));
        if ($rel === '' && $caste === '') {
            return null;
        }

        if ($rel !== '' && $caste !== '') {
            $variants = [
                'I am '.$rel.' and '.$caste.' by background.',
                'Community context: '.$rel.' · '.$caste.'.',
                'Faith and community: '.$rel.', '.$caste.'.',
            ];

            return $variants[($seed >> 7) % count($variants)];
        }
        $one = $rel !== '' ? $rel : $caste;

        return 'I am '.$one.' by background.';
    }

    private function factEducation(MatrimonyProfile $profile, int $seed): ?string
    {
        $edu = trim((string) ($profile->highest_education ?? ''));
        if ($edu === '' || strcasecmp($edu, 'Other') === 0) {
            $other = trim((string) (data_get($profile, 'highest_education_other') ?? ''));
            if ($other !== '') {
                $edu = $other;
            }
        }
        if ($edu === '') {
            return null;
        }

        $variants = [
            'My highest education is '.$edu.'.',
            'Academically I have '.$edu.'.',
            'Education: '.$edu.'.',
        ];

        return $variants[($seed >> 11) % count($variants)];
    }

    private function factAge(MatrimonyProfile $profile, int $seed): ?string
    {
        $dob = $profile->date_of_birth;
        if ($dob === null || $dob === '') {
            return null;
        }
        try {
            $age = Carbon::parse($dob)->age;
        } catch (\Throwable) {
            return null;
        }
        if ($age < 1) {
            return null;
        }

        $variants = [
            'I am '.$age.' years old.',
            'Age: '.$age.' years.',
            'I am in my '.$this->ageBucket($age).' ('.$age.' years).',
        ];

        return $variants[($seed >> 13) % count($variants)];
    }

    private function ageBucket(int $age): string
    {
        if ($age < 25) {
            return 'early twenties';
        }
        if ($age < 30) {
            return 'late twenties';
        }
        if ($age < 35) {
            return 'early thirties';
        }
        if ($age < 40) {
            return 'mid thirties';
        }

        return 'late thirties or beyond';
    }

    private function factMarital(MatrimonyProfile $profile, int $seed): ?string
    {
        $label = trim((string) ($profile->maritalStatus?->label ?? ''));
        if ($label === '') {
            return null;
        }

        $variants = [
            'Marital status: '.$label.'.',
            'I am '.$label.'.',
            'Currently '.$label.'.',
        ];

        return $variants[($seed >> 17) % count($variants)];
    }

    private function factChildren(MatrimonyProfile $profile, int $seed): ?string
    {
        $n = $profile->children()->count();
        if ($n > 0) {
            $variants = [
                'I have '.$n.' child'.($n === 1 ? '' : 'ren').'.',
                'Children: '.$n.'.',
                'Family includes '.$n.' child'.($n === 1 ? '' : 'ren').'.',
            ];

            return $variants[($seed >> 19) % count($variants)];
        }
        if ($profile->has_children) {
            $variants = [
                'I have children.',
                'My family includes children.',
            ];

            return $variants[($seed >> 19) % count($variants)];
        }

        $variants = [
            'I do not have children.',
            'No children at present.',
        ];

        return $variants[($seed >> 21) % count($variants)];
    }

    private function randomHobbyLine(int $seed): ?string
    {
        $hobbies = [
            'In my free time I enjoy reading and light travel.',
            'I like music, films, and quiet weekends.',
            'I unwind with walks, tea, and conversation.',
            'I enjoy cooking simple meals and family get-togethers.',
            'I follow cricket or local events when I can.',
            'Photography and short trips interest me.',
            'I keep a small fitness routine and steady sleep.',
            'Board games and laughter with friends matter to me.',
            'I enjoy gardening or home projects when possible.',
            'Classical or folk music relaxes me.',
        ];

        // 70% chance to attach a hobby line for variety
        if (($seed % 10) >= 7) {
            return null;
        }

        return $hobbies[$seed % count($hobbies)];
    }

    private function mixSeed(int $pid, int $catIdx, int $v): int
    {
        return (int) (crc32($pid.'|'.$catIdx.'|'.$v) & 0x7FFFFFFF);
    }

    private function pickVariantIndex(int $profileId, int $categoryIndex, int $count): int
    {
        if ($count < 1) {
            return 0;
        }

        return (int) (abs(($profileId * 31 + $categoryIndex * 17 + 13) % $count));
    }

    /**
     * @return list<string>
     */
    private function themePrefixes(): array
    {
        return [
            'Family means a great deal to me, and I hope to build a respectful partnership.',
            'I take my work seriously while keeping space for relationships and rest.',
            'I respect our traditions and still enjoy learning new perspectives every day.',
            'Honesty and clear communication matter more to me than perfection on paper.',
            'I like a balanced routine with hobbies, movement, and time with loved ones.',
            'Learning never stops for me—whether in career, skills, or understanding people.',
            'I imagine a warm home built on patience, laughter, and mutual support.',
            'I am generally calm, steady, and prefer resolving issues with patience.',
            'I am easy to get along with and value friendships and healthy social life.',
            'My faith and roots guide my values, and I respect others’ beliefs too.',
        ];
    }

    /**
     * @return list<string>
     */
    private function introFragments(): array
    {
        return [
            'I see myself as straightforward and open to a meaningful connection.',
            'I prefer a simple approach to life and relationships.',
            'I am here with a sincere intention to find the right match.',
            'I value clarity and honesty from the very first conversation.',
            'I believe small habits and daily respect matter in the long run.',
            'I enjoy steady progress rather than rushing important decisions.',
            'I am comfortable describing my expectations when the time feels right.',
            'I like balance between personal space and togetherness.',
            'I am optimistic but realistic about marriage and family life.',
            'I appreciate humour and warmth in everyday conversations.',
            'I am mindful of how both families feel in a partnership.',
            'I try to understand the other person before jumping to conclusions.',
            'I am not perfect, but I am willing to grow with the right person.',
            'I listen more than I speak when something important is on the table.',
            'I take my responsibilities seriously and still make time to unwind.',
            'I am grateful for what I have and hopeful about the future.',
            'I prefer calm discussions over long silent gaps.',
            'I am ready to invest effort in building trust step by step.',
            'I like planning together instead of assuming everything is obvious.',
            'I respect boundaries and privacy in a relationship.',
            'I am looking for emotional compatibility over superficial labels.',
            'I value health, routine, and a peaceful home environment.',
            'I am open to learning new things together as a couple.',
            'I want a partnership where both people feel heard and supported.',
        ];
    }

    /**
     * @return list<string>
     */
    private function middleFragments(): array
    {
        return [
            'Education and career are important for stability, but people matter more than titles.',
            'I take pride in my work and still keep weekends for family and rest.',
            'I enjoy learning new skills and sharing what I learn with people I trust.',
            'My routine is busy at times, yet I protect time for what truly matters.',
            'I believe financial honesty and shared goals reduce stress later.',
            'I am practical about responsibilities and soft-hearted at home.',
            'I like discussing plans openly rather than leaving things vague.',
            'I prefer a simple lifestyle with room for travel and small celebrations.',
            'I am careful with commitments, and loyal once I make them.',
            'I respect different career paths as long as there is mutual respect.',
            'I value punctuality, follow-through, and keeping promises small and big.',
            'I am comfortable with teamwork at work and the same at home.',
            'I try to stay healthy with basic habits and a calm mind.',
            'I enjoy music, movies, or quiet time—nothing too flashy.',
            'I am curious about new ideas but grounded in my values.',
            'I believe marriage is a partnership of equals, not a scoreboard.',
            'I am fine with adjusting habits when both sides agree.',
            'I appreciate someone who communicates clearly and kindly.',
            'I want growth in career and relationship without losing peace.',
            'I am organised enough for daily life and flexible enough for surprises.',
            'I respect elders and still want decisions we both agree on.',
            'I like celebrating small wins together.',
            'I am serious about compatibility, not just ticking boxes.',
            'I hope we can laugh together even on ordinary days.',
        ];
    }

    /**
     * @return list<string>
     */
    private function outroFragments(): array
    {
        return [
            'I am looking for mutual respect between families and clear expectations.',
            'I prefer a partner who values honesty and patience in difficult moments.',
            'I hope we can discuss lifestyle and priorities without pressure.',
            'I believe trust grows when both sides are consistent and kind.',
            'I want a relationship where both people feel safe to speak up.',
            'I respect faith and culture without forcing anyone into a rigid box.',
            'I am open to reasonable compromises when both sides understand why.',
            'I value emotional safety as much as practical compatibility.',
            'I hope to build a calm home with room for laughter and support.',
            'I appreciate someone who is kind to family and clear about boundaries.',
            'I want friendship at the core of marriage, not only formal roles.',
            'I am ready to invest time in knowing someone before big decisions.',
            'I look for stability in character more than outward show.',
            'I hope we can travel sometimes and enjoy quiet evenings too.',
            'I believe good communication prevents small issues from becoming big ones.',
            'I want a partner who listens and also shares their thoughts honestly.',
            'I respect privacy and expect the same in return.',
            'I am looking for someone who is serious about marriage but not rigid.',
            'I value health, balance, and a positive outlook on life.',
            'I hope we can support each other’s goals without competition.',
            'I want a partnership where both families feel respected.',
            'I am looking for clarity, kindness, and a shared sense of purpose.',
            'I believe patience and respect matter more than perfect matches on paper.',
            'I look forward to a sincere journey with someone who values the same.',
        ];
    }
}
