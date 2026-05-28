@props([
    'shareTools' => null,
    'idPrefix' => 'referral',
    'showUrl' => true,
    'compact' => false,
])

@if (! empty($shareTools) && is_array($shareTools))
    @php
        $shareUrl = (string) ($shareTools['share_url'] ?? '');
        $referralCode = (string) ($shareTools['referral_code'] ?? '');
        $whatsappUrl = (string) ($shareTools['whatsapp_url'] ?? '');
        $copyBtnId = $idPrefix.'-copy-btn';
        $urlElId = $idPrefix.'-share-url';
    @endphp
    <div {{ $attributes->merge(['class' => '']) }}>
        @if ($referralCode !== '' && ! $compact)
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('referrals.code_label') }}:
                <span class="font-mono font-bold text-gray-900 dark:text-gray-100">{{ $referralCode }}</span>
            </p>
        @endif
        @if ($showUrl && $shareUrl !== '')
            <p class="{{ $compact ? 'mt-0 text-xs' : 'mt-2 text-sm' }} font-mono text-gray-800 break-all dark:text-gray-200" id="{{ $urlElId }}">{{ $shareUrl }}</p>
        @endif
        <div class="mt-3 flex flex-wrap gap-2">
            @if ($shareUrl !== '')
                <button
                    type="button"
                    id="{{ $copyBtnId }}"
                    data-copy-url="{{ $shareUrl }}"
                    data-label="{{ __('referrals.share_copy') }}"
                    data-copied="{{ __('referrals.share_copied') }}"
                    class="inline-flex items-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 dark:bg-rose-500 dark:hover:bg-rose-600"
                >
                    {{ __('referrals.share_copy') }}
                </button>
            @endif
            @if ($whatsappUrl !== '')
                <a
                    href="{{ $whatsappUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 rounded-xl bg-[#25D366] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1fb855]"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-5 w-5 shrink-0 fill-current" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    {{ __('referrals.share_whatsapp') }}
                </a>
            @endif
        </div>
    </div>

    @once
        <script>
        (function () {
            document.querySelectorAll('[id$="-copy-btn"][data-copy-url]').forEach(function (btn) {
                if (btn.dataset.referralCopyBound === '1') return;
                btn.dataset.referralCopyBound = '1';
                btn.addEventListener('click', function () {
                    var url = btn.getAttribute('data-copy-url') || '';
                    var copied = btn.getAttribute('data-copied') || 'Copied';
                    var label = btn.getAttribute('data-label') || 'Copy';
                    if (!url) return;
                    var done = function () {
                        btn.textContent = copied;
                        setTimeout(function () { btn.textContent = label; }, 2000);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(url).then(done).catch(function () {
                            window.prompt(label, url);
                        });
                    } else {
                        window.prompt(label, url);
                    }
                });
            });
        })();
        </script>
    @endonce
@endif
