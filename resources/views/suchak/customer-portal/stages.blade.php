@extends('layouts.app')

{{--
    The CUSTOMER's door onto the marketplace stage ladder (blueprint 6a, D11, D23).

    Latin digits only. No money anywhere on this page on purpose: D17 says the screen where a family
    is deciding about a person carries this meeting's fee alone and never a running total, and this
    is not the approval screen at all — it is where they say what they did.

    D27: every line here is something the reader acts on. What the link does and does not prove is
    the one exception, and it stays because it changes what the family should expect the record to
    be worth later.

    LANGUAGE: every sentence comes from `suchak.customer_portal.stages.*`, so this page answers in
    whatever language the reader asked for. It used to hold Marathi string literals with no __() at
    all, which meant the layout's en/mr switcher changed nothing here — on the one page a family is
    ever sent. Stage names come from SuchakCollaborationStageEvent::stageLabel(), the ladder's own
    single label vocabulary, which is now locale-aware too.
--}}

@section('content')
@php
    $stageEventModel = \App\Models\SuchakCollaborationStageEvent::class;
@endphp

<div class="mx-auto max-w-3xl px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('suchak.customer_portal.stages.title') }}</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            {{ __('suchak.customer_portal.stages.intro') }}
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
        {{--
            The link text sits INSIDE the sentence, so the sentence takes it as a placeholder rather
            than being glued together from fragments: the words either side of the link do not fall
            in the same order in both languages.
        --}}
        @php
            $identifyLink = '<a class="font-semibold underline" href="'
                .e(route('suchak.customer-portal.show', ['token' => $token]))
                .'">'.e(__('suchak.customer_portal.stages.identify_link')).'</a>';
        @endphp
        <div class="mb-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {!! __('suchak.customer_portal.stages.identify', ['link' => $identifyLink]) !!}
        </div>
    @else
        <p class="mb-5 text-sm text-gray-600 dark:text-gray-300">
            {{ __('suchak.customer_portal.stages.link_user') }} {{ $portalLink->claimed_name }}
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
                {{ $row['proposed_name'] ?: __('suchak.customer_portal.stages.unnamed_profile') }}
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
                    {{ __('suchak.customer_portal.stages.clause_binds', [
                        'date' => \Illuminate\Support\Carbon::parse($clause['binds_until'])->format('d M Y'),
                    ]) }}
                </p>
            @elseif ($clause !== null && $clause['release_reason'] === \App\Modules\Suchak\Services\SuchakTwelveMonthClauseService::RELEASE_PRIOR_ACQUAINTANCE)
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('suchak.customer_portal.stages.clause_released_prior') }}
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
                        {{--
                            Keyed by the ladder's own stage key, so a rung with no help sentence
                            prints nothing rather than printing the key at the family.
                        --}}
                        @php
                            $helpKey = "suchak.customer_portal.stages.help.{$stageKey}";
                        @endphp
                        <span class="text-sm text-gray-600 dark:text-gray-300">
                            {{ \Illuminate\Support\Facades\Lang::has($helpKey) ? __($helpKey) : '' }}
                        </span>

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
                                    {{ __('suchak.customer_portal.stages.prior_acquaintance_label') }}
                                    <span class="text-gray-500 dark:text-gray-400">
                                        {{ __('suchak.customer_portal.stages.prior_acquaintance_help', [
                                            'months' => $clauseTerms['binding_months'],
                                        ]) }}
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
            {{ __('suchak.customer_portal.stages.empty') }}
        </p>
    @endforelse

    {{--
        D23 / section 8, said to the family and not only in the code: this link records that
        somebody holding it acted. There is no OTP yet, so it does not record WHO. A family that
        expects this to be proof of identity later would be wrong, and they should know now.
    --}}
    <p class="mt-6 text-xs text-gray-500 dark:text-gray-400">
        {{ __('suchak.customer_portal.stages.link_proof_note') }}
    </p>
</div>
@endsection
