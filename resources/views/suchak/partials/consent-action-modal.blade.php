@php
    $defaultMethod = \App\Models\SuchakConsent::METHOD_SUCHAK_RELAYED_LINK;
    $modalKey = 'consent-modal-'.preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($modalKey ?? uniqid('consent-', false)));
    $defaultConsentType = $defaultConsentType ?? \App\Models\SuchakConsent::TYPE_ONE_YEAR;
    $defaultConsentMobile = (string) ($defaultConsentMobile ?? '');
    $defaultConsentGiverName = (string) ($defaultConsentGiverName ?? '');
    $defaultConsentRelation = (string) ($defaultConsentRelation ?? 'candidate_self');
    /*
     * Wording lives in `suchak.consent.modal.*` and the relation list in
     * `suchak.consent.relations.*`.
     *
     * This partial used to hold its own `$isMr ? [...] : [...]` array — a
     * second translation mechanism beside __(), invisible to the translations
     * table — plus a THIRD copy of the consent-giver relation list, which
     * silently overrode the English array the caller passed in.
     */
    $buttonLabel = $buttonLabel ?? __('suchak.consent.modal.trigger');
    $buttonClass = $buttonClass ?? 'rounded-md bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white';
    $showTriggerButton = (bool) ($showTriggerButton ?? true);

    // The caller may narrow WHICH relations are offered; the WORDING is never
    // the caller's to supply, so only the keys are taken from it.
    $consentRelationKeys = array_keys($consentRelationOptions ?? __('suchak.consent.relations'));
@endphp

@once
    <script>
        window.submitSuchakConsentShareForm = window.submitSuchakConsentShareForm || function (event, state, shareMethod) {
            const form = event.target;

            if (!state || state.method !== shareMethod) {
                form.submit();
                return;
            }

            if (state.submitting) {
                return;
            }

            state.submitting = true;

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).then(async function (response) {
                const contentType = response.headers.get('content-type') || '';

                if (!contentType.includes('application/json')) {
                    throw new Error('Unexpected consent response.');
                }

                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || 'Consent request failed.');
                }

                if (payload.whatsapp_url) {
                    window.open(payload.whatsapp_url, '_blank', 'noopener');
                }

                window.location.href = payload.redirect_url || window.location.href;
            }).catch(function () {
                state.submitting = false;
                form.submit();
            });
        };
    </script>
@endonce

<div
    x-data="{ open: false, method: '{{ $defaultMethod }}', submitting: false }"
    x-on:open-consent-modal-{{ $representationId }}.window="open = true; method = '{{ $defaultMethod }}'"
    class="{{ $wrapperClass ?? '' }}"
>
    @if ($showTriggerButton)
        <button type="button" x-on:click="open = true; method = '{{ $defaultMethod }}'" class="{{ $buttonClass }}">
            {{ $buttonLabel }}
        </button>
    @endif

    <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-end justify-center bg-gray-950/50 p-3 sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="{{ $modalKey }}-title">
        <div x-on:click.outside="open = false" class="max-h-[86vh] w-full overflow-y-auto rounded-lg bg-white p-5 shadow-xl dark:bg-gray-900 sm:max-w-xl">
            <div class="flex items-start justify-between gap-3 border-b border-gray-200 pb-4 dark:border-gray-700">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('suchak.consent.modal.eyebrow') }}</p>
                    <h3 id="{{ $modalKey }}-title" class="mt-1 text-xl font-bold text-gray-950 dark:text-gray-50">{{ __('suchak.consent.modal.title') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('suchak.consent.modal.intro') }}</p>
                </div>
                <button type="button" x-on:click="open = false" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">{{ __('suchak.consent.modal.close') }}</button>
            </div>

            <div class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/30" x-show="method === '{{ \App\Models\SuchakConsent::METHOD_SUCHAK_RELAYED_LINK }}'">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800 dark:text-emerald-100">{{ __('suchak.consent.modal.consent_type') }}</p>
                <p class="mt-1 text-xl font-bold text-emerald-950 dark:text-emerald-100">{{ __('suchak.consent.modal.whatsapp_title') }}</p>
                <p class="mt-1 text-xs text-emerald-900 dark:text-emerald-100">{{ __('suchak.consent.modal.whatsapp_body') }}</p>
            </div>
            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30" x-show="method === '{{ \App\Models\SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF }}'">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-100">{{ __('suchak.consent.modal.consent_type') }}</p>
                <p class="mt-1 text-xl font-bold text-amber-950 dark:text-amber-100">{{ __('suchak.consent.modal.offline_title') }}</p>
                <p class="mt-1 text-xs text-amber-900 dark:text-amber-100">{{ __('suchak.consent.modal.offline_body') }}</p>
            </div>
            <div class="mt-4 rounded-md border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30" x-show="method === '{{ \App\Models\SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK }}'">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-800 dark:text-blue-100">{{ __('suchak.consent.modal.consent_type') }}</p>
                <p class="mt-1 text-xl font-bold text-blue-950 dark:text-blue-100">{{ __('suchak.consent.modal.platform_title') }}</p>
                <p class="mt-1 text-xs text-blue-900 dark:text-blue-100">{{ __('suchak.consent.modal.platform_body') }}</p>
            </div>

            <div class="mt-4">
                @foreach ([
                    \App\Models\SuchakConsent::METHOD_SUCHAK_RELAYED_LINK,
                    \App\Models\SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK,
                    \App\Models\SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF,
                ] as $methodOption)
                    <form x-show="method === '{{ $methodOption }}'" method="POST" action="{{ $consentAction }}" x-on:submit.prevent="submitSuchakConsentShareForm($event, $data, '{{ \App\Models\SuchakConsent::METHOD_SUCHAK_RELAYED_LINK }}')" @if ($methodOption === \App\Models\SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF) enctype="multipart/form-data" @endif class="space-y-3">
                        @csrf
                        <input type="hidden" name="consent_type" value="{{ $defaultConsentType }}">
                        <input type="hidden" name="consent_method" value="{{ $methodOption }}">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="{{ $modalKey }}-giver-{{ $methodOption }}" class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ __('suchak.consent.modal.giver_name') }}</label>
                                <input id="{{ $modalKey }}-giver-{{ $methodOption }}" name="consent_given_by_name" value="{{ $defaultConsentGiverName }}" required maxlength="255" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                            </div>
                            <div>
                                <label for="{{ $modalKey }}-relation-{{ $methodOption }}" class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ __('suchak.consent.modal.relation') }}</label>
                                <select id="{{ $modalKey }}-relation-{{ $methodOption }}" name="consent_giver_relation" required class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                    @foreach ($consentRelationKeys as $relationKey)
                                        <option value="{{ $relationKey }}" @selected($defaultConsentRelation === $relationKey)>{{ __('suchak.consent.relations.'.$relationKey) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label for="{{ $modalKey }}-mobile-{{ $methodOption }}" class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ __('suchak.consent.modal.requested_mobile') }}</label>
                                <input id="{{ $modalKey }}-mobile-{{ $methodOption }}" name="intended_mobile" value="{{ $defaultConsentMobile }}" required maxlength="20" inputmode="tel" class="mt-1 w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('suchak.consent.modal.mobile_help') }}</p>
                            </div>
                        </div>
                        @if ($methodOption === \App\Models\SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF)
                            <div>
                                <label for="{{ $modalKey }}-proof" class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ __('suchak.consent.modal.signed_file') }}</label>
                                <input id="{{ $modalKey }}-proof" type="file" name="proof_document" required accept=".pdf,.jpg,.jpeg,.png,.webp" class="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                            </div>
                            <label class="flex items-start gap-2 text-xs text-gray-600 dark:text-gray-300">
                                <input type="checkbox" name="declaration" value="1" required class="mt-0.5 rounded border-gray-300 text-emerald-600 shadow-sm dark:border-gray-700 dark:bg-gray-950">
                                <span>{{ __('suchak.consent.modal.declaration') }}</span>
                            </label>
                        @endif
                        <button type="submit" x-bind:disabled="submitting && method === '{{ $methodOption }}'" class="mt-2 min-h-14 w-full rounded-md bg-emerald-600 px-4 py-4 text-base font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600">
                            {{ $methodOption === \App\Models\SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF ? __('suchak.consent.modal.upload_proof') : ($methodOption === \App\Models\SuchakConsent::METHOD_SUCHAK_RELAYED_LINK ? __('suchak.consent.modal.send_whatsapp') : __('suchak.consent.modal.create_request')) }}
                        </button>
                    </form>
                @endforeach
            </div>

            <div class="mt-8 border-t border-gray-200 pt-6 text-center dark:border-gray-700">
                <div class="flex items-center gap-3">
                    <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('suchak.consent.modal.other_options') }}</span>
                    <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-center gap-3 text-sm">
                    <button type="button" x-show="method !== '{{ \App\Models\SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF }}'" x-on:click="method = '{{ \App\Models\SuchakConsent::METHOD_OFFLINE_SIGNED_PROOF }}'" class="font-semibold text-blue-900 underline-offset-4 hover:underline dark:text-blue-200">
                        {{-- Same key as the card heading: these two were word-for-word identical in both languages. --}}
                        {{ __('suchak.consent.modal.offline_title') }}
                    </button>
                    <span class="text-gray-300 dark:text-gray-700" x-show="method === '{{ \App\Models\SuchakConsent::METHOD_SUCHAK_RELAYED_LINK }}'">|</span>
                    <button type="button" x-show="method !== '{{ \App\Models\SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK }}'" x-on:click="method = '{{ \App\Models\SuchakConsent::METHOD_PLATFORM_ASSISTED_LINK }}'" class="font-semibold text-blue-900 underline-offset-4 hover:underline dark:text-blue-200">
                        {{ __('suchak.consent.modal.platform_title') }}
                    </button>
                    <button type="button" x-show="method !== '{{ \App\Models\SuchakConsent::METHOD_SUCHAK_RELAYED_LINK }}'" x-on:click="method = '{{ \App\Models\SuchakConsent::METHOD_SUCHAK_RELAYED_LINK }}'" class="font-semibold text-blue-900 underline-offset-4 hover:underline dark:text-blue-200">
                        {{ __('suchak.consent.modal.send_whatsapp') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
