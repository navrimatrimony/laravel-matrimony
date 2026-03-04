{{--
    Contact Field Engine: 10-digit mobile, optional country code (+91), optional WhatsApp toggle.
    Use at: user mobile (register, mobile-verify), primary contact (basic_info, contacts), additional contacts (contacts), relative/sibling/spouse mobile (relation-details), intake preview contacts.
    Add later: father mobile, mother mobile (personal_family).
--}}
@props([
    'name' => 'phone_number',
    'value' => '',
    'label' => 'Mobile',
    'placeholder' => '10-digit number',
    'showCountryCode' => true,
    'valueCountryCode' => '+91',
    'nameCountryCode' => null,
    'showWhatsapp' => false,
    'nameWhatsapp' => null,
    'valueWhatsapp' => false,
    'required' => false,
    'inputClass' => '',
])
@php
    $digits = preg_replace('/\D/', '', (string) $value);
    $displayValue = strlen($digits) <= 10 ? $digits : substr($digits, -10);
    $nameWhatsapp = $nameWhatsapp ?? $name . '_whatsapp';
    $countryCodeValue = old($nameCountryCode ?? 'country_code_dummy', $valueCountryCode);
@endphp
<div class="contact-field-engine">
    @if($label)
        <label for="{{ $name }}" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">{{ $label }}</label>
    @endif
    <div class="flex items-center gap-1.5 flex-nowrap contact-master-field">
        @if($showCountryCode)
            <input type="text"
                   inputmode="tel"
                   maxlength="5"
                   value="{{ $countryCodeValue }}"
                   @if($nameCountryCode) name="{{ $nameCountryCode }}" @endif
                   placeholder="+91"
                   title="Country code"
                   class="text-xs text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600 rounded py-1.5 bg-gray-50 dark:bg-gray-700 h-9 box-border text-center"
                   style="flex:0 0 10%; min-width:2.75rem; width:10%; padding-left:0.25rem; padding-right:0.25rem;">
        @endif
        <input type="text"
               inputmode="numeric"
               pattern="[0-9]*"
               maxlength="10"
               id="{{ $name }}"
               name="{{ $name }}"
               value="{{ old($name, $displayValue) }}"
               placeholder="{{ $placeholder }}"
               data-contact-engine
               class="h-9 rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0 flex-1 {{ $inputClass }}"
               style="flex:1 1 90%; min-width:0;"
               {{ $required ? 'required' : '' }}
               autocomplete="tel">
        @if($showWhatsapp)
            <label class="flex items-center gap-1 shrink-0 cursor-pointer" title="WhatsApp">
                <input type="checkbox" name="{{ $nameWhatsapp }}" value="1" {{ old($nameWhatsapp, $valueWhatsapp) ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600 w-4 h-4">
                <span class="inline-flex items-center justify-center w-5 h-5" aria-hidden="true">{{-- WhatsApp logo --}}
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#25D366" aria-label="WhatsApp"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                </span>
            </label>
        @endif
    </div>
    <p class="contact-field-error hidden text-xs text-red-600 dark:text-red-400 mt-0.5" data-contact-error-for="{{ $name }}">10-digit mobile number required.</p>
</div>
<script>
(function() {
    document.querySelectorAll('[data-contact-engine]').forEach(function(inp) {
        if (inp.dataset.contactBound === '1') return;
        inp.dataset.contactBound = '1';
        inp.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 10);
            var err = document.querySelector('[data-contact-error-for="' + this.name + '"]');
            if (err) err.classList.add('hidden');
        });
        inp.addEventListener('invalid', function() {
            var err = document.querySelector('[data-contact-error-for="' + this.name + '"]');
            if (err && this.value.trim() !== '' && this.value.replace(/\D/g, '').length !== 10) err.classList.remove('hidden');
        });
    });
})();
</script>
