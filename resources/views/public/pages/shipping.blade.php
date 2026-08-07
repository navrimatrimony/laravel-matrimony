@extends('public.pages.layout')

@section('page_title', __('public_pages.shipping.title'))
@section('page_summary', __('public_pages.shipping.summary'))
@section('og_title'){{ __('public_pages.shipping.title') }} — {{ $siteName }}@endsection
@section('og_description'){{ __('public_pages.shipping.meta_description') }}@endsection

@section('content')

    {{-- The authoritative wording ------------------------------------------
         This block is NOT written here. It is the Refund and Cancellation
         Policy's own clause, pulled live through LegalDocument::content('refund')
         in routes/web.php and quoted verbatim, so this page can never drift into
         saying something the policy does not. If the policy is reworded, this
         page changes with it; if the clause is ever removed, $refundClause is
         null and the page falls back to the link alone rather than publishing a
         stale second version. --}}
    @if ($refundClause !== null)
        <section class="px-4 py-10 sm:px-6 sm:py-14">
            <div class="mx-auto max-w-4xl">
                <figure class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 shadow-sm sm:p-8">
                    <svg class="h-7 w-7 text-red-200" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 5.5C6.46 6.9 4.5 9.7 4.5 13v5.5h6V13H7.9c.1-1.9 1-3.3 2.7-4.1l-1.1-3.4Zm9 0C15.46 6.9 13.5 9.7 13.5 13v5.5h6V13h-2.6c.1-1.9 1-3.3 2.7-4.1l-1.1-3.4Z" /></svg>

                    @if ($refundClause['heading'] !== '')
                        <h2 class="{{ $devanagariClass }} mt-3 text-lg font-extrabold text-[#201a1a] sm:text-xl">{{ $refundClause['heading'] }}</h2>
                    @endif

                    <blockquote class="mt-4 space-y-4">
                        @foreach ($refundClause['body'] as $refundParagraph)
                            <p class="{{ $devanagariClass }} text-base leading-8 text-zinc-800">{{ $refundParagraph }}</p>
                        @endforeach
                    </blockquote>

                    <figcaption class="{{ $devanagariClass }} mt-5 border-t border-zinc-200 pt-4 text-xs leading-6 text-zinc-500">
                        @if (! empty($refundPolicyLink['url']))
                            {!! __('public_pages.shipping.quoted_from', [
                                'clause' => e($refundClause['number']),
                                'policy' => '<a href="'.e($refundPolicyLink['url']).'" class="font-semibold text-[color:var(--brand-red)] hover:underline">'.e($refundPolicyLink['label']).'</a>',
                            ]) !!}
                        @endif
                        <span class="mt-1 block">{{ __('public_pages.shipping.source_note') }}</span>
                    </figcaption>
                </figure>
            </div>
        </section>
    @endif

    {{-- No physical delivery ------------------------------------------------- --}}
    <section class="border-y border-zinc-200 bg-zinc-50 px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto grid max-w-6xl gap-8 lg:grid-cols-2">

            <div>
                <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.shipping.digital_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-600">{{ __('public_pages.shipping.digital_body') }}</p>
            </div>

            <div>
                <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.shipping.activation_title') }}</h2>
                <ul class="mt-4 space-y-3" role="list">
                    @foreach ((array) __('public_pages.shipping.activation_list') as $activationItem)
                        <li class="flex gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3.5 shadow-sm" role="listitem">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                            <span class="{{ $devanagariClass }} text-sm leading-7 text-zinc-700">{{ $activationItem }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- If it did not activate + refunds ------------------------------------- --}}
    <section class="px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto grid max-w-6xl gap-6 lg:grid-cols-2">

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="{{ $devanagariClass }} text-lg font-extrabold text-[#201a1a]">{{ __('public_pages.shipping.problem_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-700">{{ __('public_pages.shipping.problem_body') }}</p>

                <div class="mt-4 flex flex-wrap gap-3">
                    @if ($identity['mobile'] !== '')
                        <a href="tel:{{ $identity['tel'] }}" class="rounded-lg bg-[color:var(--brand-red)] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[color:var(--brand-red-dark)]">{{ $identity['mobile'] }}</a>
                    @endif
                    @if ($identity['email'] !== '')
                        <a href="mailto:{{ $identity['email'] }}" class="rounded-lg border border-red-200 px-4 py-2.5 text-sm font-bold text-[color:var(--brand-red)] transition hover:bg-red-50">{{ $identity['email'] }}</a>
                    @endif
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="{{ $devanagariClass }} text-lg font-extrabold text-[#201a1a]">{{ __('public_pages.shipping.refund_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-700">{{ __('public_pages.shipping.refund_body') }}</p>

                @if (! empty($refundPolicyLink['url']))
                    <a href="{{ $refundPolicyLink['url'] }}" class="{{ $devanagariClass }} mt-4 inline-flex items-center gap-2 text-sm font-bold text-[color:var(--brand-red)] hover:underline">
                        {{ __('public_pages.common.read_full_policy') }}
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                @endif
            </div>
        </div>
    </section>

@endsection
