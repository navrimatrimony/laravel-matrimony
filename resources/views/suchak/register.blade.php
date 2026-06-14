@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-5xl px-3 py-2 sm:px-4 sm:py-5">
    <div class="mb-2 flex flex-col gap-1.5 sm:mb-4 sm:flex-row sm:items-end sm:justify-between sm:gap-3">
        <div>
            <a href="{{ route('suchak.home') }}" class="text-sm font-semibold text-red-700 hover:underline dark:text-red-300">{{ __('suchak.register.back') }}</a>
            <h1 class="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100 sm:text-2xl">{{ __('suchak.register.title') }}</h1>
            <p class="mt-0.5 max-w-2xl text-xs leading-5 text-gray-600 dark:text-gray-300 sm:mt-1 sm:text-sm">
                {{ __('suchak.register.intro') }}
            </p>
        </div>
        <a href="{{ route('login') }}" class="text-sm font-semibold text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
            {{ __('suchak.register.already_registered') }}
        </a>
    </div>

    @if (session('error') || session('info') || session('status'))
        <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ session('error') ?: session('info') ?: session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            <p class="font-semibold">{{ __('suchak.register.fix_information') }}</p>
            <ul class="mt-1 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($authenticatedUser)
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ __('suchak.register.separate_account_title') }}</h2>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                {{ __('suchak.register.separate_account_body') }}
            </p>
            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf
                <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                    {{ __('suchak.register.logout_register') }}
                </button>
            </form>
        </section>
    @else
        <section class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <form id="suchak-register-form" method="POST" action="{{ route('suchak.register.store') }}" data-suchak-registration-form>
                @csrf

                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-3 py-2 dark:border-gray-700 sm:px-5 sm:py-3">
                    <div class="inline-flex rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white">{{ __('suchak.register.step_info') }}</div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('suchak.register.admin_review_note') }}</p>
                </div>

                <div class="p-3 sm:p-5">
                    <div class="grid gap-3 lg:grid-cols-2 sm:gap-4">
                        <div>
                            <label for="suchak_name" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.register.suchak_name') }}</label>
                            <input id="suchak_name" name="suchak_name" value="{{ old('suchak_name') }}" required maxlength="255" autocomplete="name" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>

                        <div>
                            <label for="business_type" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.register.business_type') }}</label>
                            <select id="business_type" name="business_type" required data-suchak-business-type class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                @foreach ($businessTypes as $value => $label)
                                    <option value="{{ $value }}" @selected(old('business_type', 'individual') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div data-office-name-wrapper>
                            <label for="office_name" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.register.office_name') }}</label>
                            <input id="office_name" name="office_name" value="{{ old('office_name') }}" maxlength="255" data-suchak-office-name class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>

                        <div>
                            <label for="whatsapp_number" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.register.whatsapp_number') }}</label>
                            <input id="whatsapp_number" name="whatsapp_number" value="{{ old('whatsapp_number', old('mobile_number')) }}" required maxlength="32" inputmode="numeric" autocomplete="tel" placeholder="{{ __('suchak.register.whatsapp_placeholder') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('suchak.register.whatsapp_help') }}</p>
                        </div>

                        <div>
                            <label for="email" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.register.email') }}</label>
                            <input id="email" name="email" value="{{ old('email') }}" type="email" maxlength="255" autocomplete="email" placeholder="{{ __('suchak.register.optional') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>

                        @php
                            $oldSuchakLocationId = old('location_id');
                            $oldSuchakLocationDisplay = old('location_input', '');
                            if ($oldSuchakLocationDisplay === '' && filled($oldSuchakLocationId)) {
                                $oldSuchakLocation = \App\Models\Location::query()->find((int) $oldSuchakLocationId);
                                $oldSuchakLocationDisplay = $oldSuchakLocation?->display_label ?? '';
                            }
                        @endphp
                        <div class="lg:col-span-2">
                            <x-profile.location-typeahead
                                id="suchak_office_location"
                                context="residence"
                                mode="full"
                                detailedLabel="{{ __('suchak.register.address') }}"
                                detailedPlaceholder="{{ __('suchak.register.address_placeholder') }}"
                                detailedName="address_line"
                                :detailedValue="old('address_line')"
                                :detailedMaxlength="1000"
                                :detailedRequired="true"
                                label="{{ __('suchak.register.office_location') }}"
                                placeholder="{{ __('suchak.register.office_location_placeholder') }}"
                                :value="$oldSuchakLocationDisplay"
                                :dataLocationId="$oldSuchakLocationId ?? ''"
                                :gpsAssist="true"
                                :resolveUrl="route('suchak.register.resolve-current-location')"
                                :gpsAutoApply="true"
                                :flush="true"
                            />
                        </div>

                        <div class="grid gap-3 lg:col-span-2 sm:grid-cols-2">
                            <div>
                                <label for="password" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.register.password') }}</label>
                                <input id="password" name="password" type="password" required autocomplete="new-password" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </div>

                            <div>
                                <label for="password_confirmation" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('suchak.register.confirm_password') }}</label>
                                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('suchak.register.password_match_help') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-t border-gray-200 px-3 py-2 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-5 sm:py-4">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('suchak.register.separate_account_note') }}
                    </div>
                    <button type="submit" class="rounded-md bg-red-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-red-700">
                        {{ __('suchak.register.submit') }}
                    </button>
                </div>
            </form>
        </section>
    @endif
</div>

<script>
    (function () {
        var form = document.getElementById('suchak-register-form');
        if (!form) return;

        var businessType = form.querySelector('[data-suchak-business-type]');
        var officeName = form.querySelector('[data-suchak-office-name]');
        var officeNameWrapper = form.querySelector('[data-office-name-wrapper]');

        var needsOfficeProof = function () {
            return businessType && (businessType.value === 'bureau' || businessType.value === 'organization');
        };

        var syncOfficeRequirements = function () {
            var required = needsOfficeProof();
            if (officeName) {
                officeName.required = required;
                officeName.disabled = !required;
            }
            if (officeNameWrapper) {
                officeNameWrapper.classList.toggle('hidden', !required);
            }
        };

        syncOfficeRequirements();

        if (businessType) {
            businessType.addEventListener('change', syncOfficeRequirements);
        }
        form.addEventListener('submit', function () {
            syncOfficeRequirements();
            var button = form.querySelector('button[type="submit"]');
            if (!button || button.dataset.once) return;
            button.dataset.once = '1';
            button.disabled = true;
        });
    })();
</script>
@endsection
