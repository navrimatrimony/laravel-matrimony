@extends('layouts.app')

{{--
    The CUSTOMER's door onto the marketplace stage ladder (blueprint 6a, D11, D23).

    Marathi only, Latin digits only. No money anywhere on this page on purpose: D17 says the screen
    where a family is deciding about a person carries this meeting's fee alone and never a running
    total, and this is not the approval screen at all — it is where they say what they did.

    D27: every line here is something the reader acts on. What the link does and does not prove is
    the one exception, and it stays because it changes what the family should expect the record to
    be worth later.
--}}

@section('content')
@php
    $stageEventModel = \App\Models\SuchakCollaborationStageEvent::class;
    $stageHelp = [
        $stageEventModel::STAGE_VIEWED => 'हे स्थळ तुम्ही पाहिले.',
        $stageEventModel::STAGE_INTERESTED => 'हे स्थळ तुम्हाला पसंत आहे.',
        $stageEventModel::STAGE_MEETING_CONFIRMED => 'ठरलेली भेट प्रत्यक्षात झाली.',
    ];
@endphp

<div class="mx-auto max-w-3xl px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">तुमच्यासाठी सुचवलेली स्थळे</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            प्रत्येक स्थळाबाबत तुम्ही काय केले ते इथे नोंदवा. ही नोंद तुमचीच राहते — सूचक ती तुमच्या वतीने करू शकत नाही.
        </p>
    </div>

    @if (session('success'))
        <div class="mb-5 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900 dark:border-green-900 dark:bg-green-950/40 dark:text-green-100">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            {{ $errors->first() }}
        </div>
    @endif

    @if ($portalLink->claimed_name === null)
        <div class="mb-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            नोंद कोणी केली हे कळावे म्हणून आधी
            <a class="font-semibold underline" href="{{ route('suchak.customer-portal.show', ['token' => $token]) }}">तुमचे नाव आणि नाते</a>
            नोंदवा.
        </div>
    @else
        <p class="mb-5 text-sm text-gray-600 dark:text-gray-300">
            ही लिंक वापरणारे: {{ $portalLink->claimed_name }}
            @if ($portalLink->claimed_relationship_to_candidate)
                ({{ $portalLink->claimed_relationship_to_candidate }})
            @endif
        </p>
    @endif

    @forelse ($engagements as $row)
        @php
            $collaboration = $row['collaboration'];
            $recorded = $row['recorded'];
            $clause = $row['clause'] ?? null;
        @endphp
        <section class="mb-5 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ $row['proposed_name'] ?: 'सुचवलेले स्थळ' }}
            </h2>

            {{--
                D11 / D21, in the family's own words. This is the only screen the party BOUND by the
                clause ever sees, and D27's test passes: a family that knows this date decides
                differently about what to do next. Latin digits, and no rupee figure — the amount is
                on their payments screen, not on the screen where they are deciding about a person
                (D17).
            --}}
            @if ($clause !== null && $clause['binds'] === true)
                <p class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                    हे स्थळ तुम्ही पाहिले आहे. {{ \Illuminate\Support\Carbon::parse($clause['binds_until'])->format('d M Y') }}
                    पर्यंत या स्थळाशी लग्न झाल्यास तुमच्या सूचकाची ठरलेली विवाह-फी लागू राहते — सेवा
                    मध्येच थांबवली तरीही.
                </p>
            @elseif ($clause !== null && $clause['release_reason'] === \App\Modules\Suchak\Services\SuchakTwelveMonthClauseService::RELEASE_PRIOR_ACQUAINTANCE)
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    तुम्ही या कुटुंबाला आधीपासून ओळखता असे नोंदवले आहे, त्यामुळे या स्थळावर विवाह-फी लागू नाही.
                </p>
            @endif

            @if ($recorded !== [])
                <ul class="mt-3 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($recorded as $stageKey => $claimedAt)
                        <li>
                            {{ $stageEventModel::stageLabel($stageKey) }} —
                            {{ optional($claimedAt)->format('d M Y') }}
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-4 flex flex-col gap-3">
                @foreach ($stageKeys as $stageKey)
                    @if (array_key_exists($stageKey, $recorded))
                        @continue
                    @endif
                    <form
                        method="POST"
                        action="{{ route('suchak.customer-portal.stages.record', ['token' => $token, 'collaboration' => $collaboration->id]) }}"
                        class="flex flex-wrap items-center gap-3"
                    >
                        @csrf
                        <input type="hidden" name="stage_key" value="{{ $stageKey }}">
                        <button
                            type="submit"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                        >
                            {{ $stageEventModel::stageLabel($stageKey) }}
                        </button>
                        <span class="text-sm text-gray-600 dark:text-gray-300">{{ $stageHelp[$stageKey] ?? '' }}</span>

                        {{--
                            9a A6 — ONE TAP, AT VIEW TIME, and only on the rung the clause binds at.
                            It sits inside this form and nowhere else because the release is written
                            in the same act as the binding: a family that could tick it later could
                            un-owe a fee once the marriage was already in sight, and a family that
                            could not tick it at all is the family A6 exists to protect.
                        --}}
                        @if ($stageKey === $clauseAnchorStage)
                            <label class="flex w-full items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" name="prior_acquaintance" value="1" class="mt-1 rounded border-gray-300">
                                <span>
                                    आम्ही या कुटुंबाला आधीपासून ओळखतो.
                                    <span class="text-gray-500 dark:text-gray-400">
                                        असे असल्यास या स्थळावर {{ $clauseTerms['binding_months'] }} महिन्यांची विवाह-फी अट लागू होणार नाही.
                                    </span>
                                </span>
                            </label>
                        @endif
                    </form>
                @endforeach
            </div>
        </section>
    @empty
        <p class="rounded-lg border border-gray-200 bg-white p-5 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
            सध्या तुमच्यासाठी नोंदवण्यासारखे स्थळ नाही.
        </p>
    @endforelse

    {{--
        D23 / section 8, said to the family and not only in the code: this link records that
        somebody holding it acted. There is no OTP yet, so it does not record WHO. A family that
        expects this to be proof of identity later would be wrong, and they should know now.
    --}}
    <p class="mt-6 text-xs text-gray-500 dark:text-gray-400">
        या लिंकवरून केलेली नोंद "ही लिंक असलेल्या व्यक्तीने नोंद केली" एवढेच सांगते. लिंक इतर कोणाला देऊ नका.
    </p>
</div>
@endsection
