{{--
    Contact Field Engine (centralized): 10-digit mobile, optional country code (+91), optional WhatsApp toggle.
    Includes optional "+" button (showAddButton) to add another contact — part of the engine; enable where multiple contacts are supported.
    Use at: register, mobile-verify, basic_info, contacts (wizard), intake contacts, relation-details, parent-engine.
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
    'showAddButton' => false,
    /** default = bordered card; inline = no outer box (wizard relation grids). */
    'variant' => 'default',
])
@php
    $digits = preg_replace('/\D/', '', (string) $value);
    $displayValue = strlen($digits) <= 10 ? $digits : substr($digits, -10);
    $nameWhatsapp = $nameWhatsapp ?? $name . '_whatsapp';
    $countryCodeValue = old($nameCountryCode ?? 'country_code_dummy', $valueCountryCode);
    $prefVal = old($nameWhatsapp, $valueWhatsapp);
    if ($prefVal === true || $prefVal === '1' || $prefVal === 1) {
        $prefVal = 'whatsapp';
    }
    $prefVal = in_array($prefVal, ['whatsapp', 'call', 'message'], true) ? $prefVal : 'whatsapp';
    $isInline = ($variant ?? 'default') === 'inline';
    $rowH = $isInline ? 'h-10' : 'h-9';
@endphp
<div class="{{ $isInline ? 'contact-field-engine contact-field-engine--inline' : 'contact-field-engine border border-gray-200 dark:border-gray-600 rounded-lg p-3' }}">
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
                   title="{{ __('contact.country_code') }}"
                   class="text-xs text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600 rounded py-1.5 bg-gray-50 dark:bg-gray-700 {{ $rowH }} box-border text-center contact-cc-input"
                   style="flex:0 0 2.25rem; width:2.25rem; min-width:2.25rem; max-width:2.25rem; padding-left:0.2rem; padding-right:0.2rem;">
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
               class="{{ $rowH }} rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-2 py-1.5 text-sm min-w-0 flex-1 {{ $inputClass }}"
               style="flex:1 1 90%; min-width:0;"
               {{ $required ? 'required' : '' }}
               autocomplete="tel">
        @if($showWhatsapp)
            <input type="hidden" name="{{ $nameWhatsapp }}" value="{{ $prefVal }}" class="contact-preference-input" data-preference-for="{{ $name }}">
            <div class="relative shrink-0 contact-preference-single" data-current-pref="{{ $prefVal }}">
                <button type="button" class="contact-pref-trigger inline-flex items-center justify-center ring-1 ring-gray-300 dark:ring-gray-600 bg-gray-50 dark:bg-gray-700/50 {{ $isInline ? 'h-10 w-10 shrink-0 rounded p-1.5' : 'rounded p-1.5' }}" title="{{ __('contact.prefer_contact_via') }}" aria-haspopup="true" aria-expanded="false">
                    <span class="contact-pref-icon contact-pref-icon-whatsapp" data-pref="whatsapp" style="{{ $prefVal !== 'whatsapp' ? 'display:none' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-5 h-5" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg></span>
                    <span class="contact-pref-icon contact-pref-icon-call text-red-500 dark:text-red-400" data-pref="call" style="{{ $prefVal !== 'call' ? 'display:none' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 0 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                    <span class="contact-pref-icon contact-pref-icon-message" data-pref="message" style="{{ $prefVal !== 'message' ? 'display:none' : '' }}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 text-gray-600 dark:text-gray-400"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.27.17l-2.47 2.47a.75.75 0 01-1.06 0l-2.47-2.47a.39.39 0 00-.27-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd"/></svg></span>
                </button>
                <div class="contact-pref-dropdown hidden absolute right-0 top-full mt-1 z-50 min-w-[8rem] py-1 rounded-lg shadow-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600">
                    <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="whatsapp"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg> {{ __('contact.whatsapp') }}</button>
                    <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="call"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-red-500 dark:text-red-400"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 0 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> {{ __('contact.call') }}</button>
                    <button type="button" class="contact-pref-option w-full text-left px-3 py-2 text-sm flex items-center gap-2 hover:bg-rose-50 dark:hover:bg-rose-900/30" data-pref="message"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 text-gray-600 dark:text-gray-400"><path fill-rule="evenodd" d="M4.848 2.771A49.144 49.144 0 0112 2.25c2.43 0 4.817.178 7.152.52 1.978.292 3.348 2.024 3.348 3.97v6.02c0 1.946-1.37 3.678-3.348 3.97a48.901 48.901 0 01-3.476.383.39.39 0 00-.27.17l-2.47 2.47a.75.75 0 01-1.06 0l-2.47-2.47a.39.39 0 00-.27-.17 48.9 48.9 0 01-3.476-.384c-1.978-.29-3.348-2.024-3.348-3.97V6.741c0-1.946 1.37-3.68 3.348-3.97z" clip-rule="evenodd"/></svg> {{ __('contact.message') }}</button>
                </div>
            </div>
        @endif
        @if(!empty($showAddButton))
            <button type="button" class="contact-engine-add-btn shrink-0 inline-flex items-center justify-center {{ $isInline ? 'w-10 h-10' : 'w-9 h-9' }} rounded border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 text-gray-600 dark:text-gray-300 font-bold text-lg leading-none hover:bg-gray-100 dark:hover:bg-gray-600/50" title="{{ __('contact.add_another_contact') }}" aria-label="{{ __('contact.add_contact') }}">+</button>
        @endif
    </div>
    <p class="contact-field-error hidden text-xs text-red-600 dark:text-red-400 mt-0.5" data-contact-error-for="{{ $name }}">{{ __('contact.ten_digit_mobile_required') }}</p>
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
    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.contact-pref-trigger');
        var option = e.target.closest('.contact-pref-option');
        var single = e.target.closest('.contact-preference-single');
        if (option && single) {
            var pref = option.getAttribute('data-pref');
            var hidden = single.querySelector('.contact-preference-input');
            if (hidden) hidden.value = pref;
            single.setAttribute('data-current-pref', pref);
            single.querySelectorAll('.contact-pref-icon').forEach(function(span) {
                span.style.display = span.getAttribute('data-pref') === pref ? '' : 'none';
            });
            var drop = single.querySelector('.contact-pref-dropdown');
            if (drop) drop.classList.add('hidden');
            var tr = single.querySelector('.contact-pref-trigger');
            if (tr) tr.setAttribute('aria-expanded', 'false');
            return;
        }
        if (trigger) {
            var wrap = trigger.closest('.contact-preference-single');
            var drop = wrap ? wrap.querySelector('.contact-pref-dropdown') : null;
            document.querySelectorAll('.contact-pref-dropdown').forEach(function(d) { d.classList.add('hidden'); });
            document.querySelectorAll('.contact-pref-trigger').forEach(function(t) { t.setAttribute('aria-expanded', 'false'); });
            if (drop) {
                drop.classList.toggle('hidden');
                trigger.setAttribute('aria-expanded', drop.classList.contains('hidden') ? 'false' : 'true');
            }
            return;
        }
        document.querySelectorAll('.contact-pref-dropdown').forEach(function(d) { d.classList.add('hidden'); });
        document.querySelectorAll('.contact-pref-trigger').forEach(function(t) { t.setAttribute('aria-expanded', 'false'); });
    });
})();
</script>
