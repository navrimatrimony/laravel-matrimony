@php
    $fieldIdPrefix = $fieldIdPrefix ?? 'suchak_register_';
    $fieldClass = $fieldClass ?? 'mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';
    $labelClass = $labelClass ?? 'block text-sm font-semibold text-gray-700 dark:text-gray-300';
    $helpClass = $helpClass ?? 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $gridClass = $gridClass ?? 'grid gap-3 lg:grid-cols-2 sm:gap-4';
    $oldSuchakLocationId = old('location_id');
    $oldSuchakLocationDisplay = old('location_input', '');
    if ($oldSuchakLocationDisplay === '' && filled($oldSuchakLocationId)) {
        $oldSuchakLocation = \App\Models\Location::query()->find((int) $oldSuchakLocationId);
        $oldSuchakLocationDisplay = $oldSuchakLocation?->display_label ?? '';
    }
@endphp

<div class="{{ $gridClass }}">
    <div>
        <label for="{{ $fieldIdPrefix }}suchak_name" class="{{ $labelClass }}">{{ __('suchak.register.suchak_name') }}</label>
        <input id="{{ $fieldIdPrefix }}suchak_name" name="suchak_name" value="{{ old('suchak_name') }}" required maxlength="255" autocomplete="name" class="{{ $fieldClass }}">
    </div>

    <div>
        <label for="{{ $fieldIdPrefix }}business_type" class="{{ $labelClass }}">{{ __('suchak.register.business_type') }}</label>
        <select id="{{ $fieldIdPrefix }}business_type" name="business_type" required data-suchak-business-type class="{{ $fieldClass }}">
            @foreach ($businessTypes as $value => $label)
                <option value="{{ $value }}" @selected(old('business_type', 'individual') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div data-office-name-wrapper>
        <label for="{{ $fieldIdPrefix }}office_name" class="{{ $labelClass }}">{{ __('suchak.register.office_name') }}</label>
        <input id="{{ $fieldIdPrefix }}office_name" name="office_name" value="{{ old('office_name') }}" maxlength="255" data-suchak-office-name class="{{ $fieldClass }}">
    </div>

    <div>
        <label for="{{ $fieldIdPrefix }}whatsapp_number" class="{{ $labelClass }}">{{ __('suchak.register.whatsapp_number') }}</label>
        <input id="{{ $fieldIdPrefix }}whatsapp_number" name="whatsapp_number" value="{{ old('whatsapp_number', old('mobile_number')) }}" required maxlength="32" inputmode="numeric" autocomplete="tel" placeholder="{{ __('suchak.register.whatsapp_placeholder') }}" class="{{ $fieldClass }}">
        <p class="{{ $helpClass }}">{{ __('suchak.register.whatsapp_help') }}</p>
    </div>

    <div>
        <label for="{{ $fieldIdPrefix }}email" class="{{ $labelClass }}">{{ __('suchak.register.email') }}</label>
        <input id="{{ $fieldIdPrefix }}email" name="email" value="{{ old('email') }}" type="email" maxlength="255" autocomplete="email" placeholder="{{ __('suchak.register.optional') }}" class="{{ $fieldClass }}">
    </div>

    <div class="lg:col-span-2">
        <x-profile.location-typeahead
            id="{{ $fieldIdPrefix }}office_location"
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
            <label for="{{ $fieldIdPrefix }}password" class="{{ $labelClass }}">{{ __('suchak.register.password') }}</label>
            <input
                id="{{ $fieldIdPrefix }}password"
                name="password"
                type="password"
                required
                autocomplete="new-password"
                class="{{ $fieldClass }}"
            >
            <p class="{{ $helpClass }}">{{ __('suchak.register.password_help') }}</p>
        </div>

        <div>
            <label for="{{ $fieldIdPrefix }}password_confirmation" class="{{ $labelClass }}">{{ __('suchak.register.confirm_password') }}</label>
            <input
                id="{{ $fieldIdPrefix }}password_confirmation"
                name="password_confirmation"
                type="password"
                required
                autocomplete="new-password"
                class="{{ $fieldClass }}"
            >
            <p class="{{ $helpClass }}">{{ __('suchak.register.password_match_help') }}</p>
        </div>
    </div>
</div>

@once
    <script>
        (function () {
            var syncSuchakRegistrationForms = function () {
                document.querySelectorAll('[data-suchak-registration-form]').forEach(function (form) {
                    var businessType = form.querySelector('[data-suchak-business-type]');
                    var officeName = form.querySelector('[data-suchak-office-name]');
                    var officeNameWrapper = form.querySelector('[data-office-name-wrapper]');

                    var syncOfficeName = function () {
                        var required = businessType && (businessType.value === 'bureau' || businessType.value === 'organization');
                        if (officeName) {
                            officeName.required = required;
                            officeName.disabled = !required;
                            if (!required) {
                                officeName.value = '';
                            }
                        }
                        if (officeNameWrapper) {
                            officeNameWrapper.classList.toggle('hidden', !required);
                        }
                    };

                    syncOfficeName();
                    if (businessType && !businessType.dataset.suchakOfficeBound) {
                        businessType.dataset.suchakOfficeBound = '1';
                        businessType.addEventListener('change', syncOfficeName);
                    }
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', syncSuchakRegistrationForms);
            } else {
                syncSuchakRegistrationForms();
            }
        })();
    </script>
@endonce
