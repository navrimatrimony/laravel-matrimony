@php
    /** @var \App\Models\Interest $interest */
    $useBlur = (bool) ($useBlur ?? false);
    $blurPhotoClass = trim((string) ($blurPhotoClass ?? ''));
    $subLines = is_array($subLines ?? null) ? $subLines : [];
    $summaryLine = isset($summaryLine) ? (string) $summaryLine : '';
    $showUpgradePrimary = (bool) ($showUpgradePrimary ?? false);
    $textFromLowerHalf = (bool) ($textFromLowerHalf ?? true);
@endphp
<div class="relative flex min-h-0 w-full flex-1 flex-col overflow-hidden rounded-2xl ring-1 ring-black/10">
    <div class="relative mx-auto aspect-[3/4] w-full max-w-sm min-h-[17rem] max-h-[min(72vh,28rem)] sm:max-h-none sm:min-h-[19rem]">
        <img src="{{ $photoSrc }}" alt="" class="absolute inset-0 h-full w-full object-cover{{ $useBlur && $blurPhotoClass !== '' ? ' '.$blurPhotoClass : '' }}" loading="lazy">
        <div class="absolute inset-0 bg-gradient-to-t from-black via-black/55 to-transparent"></div>
        @if ($useBlur)
            <div class="pointer-events-none absolute left-1/2 top-3 z-[1] -translate-x-1/2 rounded-full bg-black/35 p-2 ring-1 ring-white/25 backdrop-blur-sm">
                <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 00-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                </svg>
            </div>
        @endif
        <div class="absolute inset-0 z-[2] flex flex-col px-3 pb-3 pt-4 sm:px-4 sm:pb-4 sm:pt-5">
            @if ($textFromLowerHalf)
                {{-- Upper ~48%: face / photo visible, no text --}}
                <div class="min-h-[48%] shrink-0" aria-hidden="true"></div>
            @endif
            <div @class([
                'flex min-h-0 flex-col items-center text-center',
                'flex-1 justify-start' => $textFromLowerHalf,
                'flex-1 justify-center px-1 pt-8' => ! $textFromLowerHalf,
            ])>
                <p class="max-w-full break-words text-lg font-bold leading-snug text-white drop-shadow sm:text-xl">{{ $headline }}</p>
                @foreach ($subLines as $line)
                    @php $t = trim((string) $line); @endphp
                    @if ($t !== '')
                        <p class="mt-2 max-w-full break-words text-sm leading-snug text-white/90 drop-shadow">{{ $t }}</p>
                    @endif
                @endforeach
                @if ($summaryLine !== '')
                    <p class="mt-3 max-w-full break-words text-xs text-white/75">{{ $summaryLine }}</p>
                @endif
            </div>
            <div class="mt-auto w-full shrink-0 space-y-2.5 pt-2 text-center">
                <p class="text-sm text-white/95">
                    <span class="text-white/65">{{ __('interests.status') }}:</span>
                    @if ($interest->status === 'pending')
                        <span class="font-semibold text-amber-200">{{ __('interests.pending') }}</span>
                    @elseif ($interest->status === 'accepted')
                        <span class="font-semibold text-emerald-300">{{ __('interests.accepted') }}</span>
                    @elseif ($interest->status === 'rejected')
                        <span class="font-semibold text-rose-300">{{ __('interests.rejected') }}</span>
                    @endif
                </p>
                @if ($sender && ! $acceptLocked)
                    <a href="{{ route('matrimony.profile.show', $sender->id) }}"
                       class="inline-flex items-center justify-center gap-1 rounded-full bg-white/95 px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-md ring-1 ring-white/50 transition hover:bg-white sm:text-sm">
                        {{ __('interests.view_matrimony_profile') }}
                        <span aria-hidden="true">→</span>
                    </a>
                @endif
                @if ($showUpgradePrimary)
                    <a href="{{ $plansUrl }}" class="inline-flex items-center justify-center rounded-full bg-rose-600 px-4 py-2 text-sm font-bold text-white shadow-lg ring-2 ring-white/30 transition hover:bg-rose-700">
                        {{ __('interests.unlock_with_membership') }} <span aria-hidden="true">→</span>
                    </a>
                @endif
                @if ($interest->status === 'pending')
                    <div class="rounded-xl bg-black/30 p-2 ring-1 ring-white/15 backdrop-blur-sm">
                        @include('interests.partials.received-interest-pending-actions', [
                            'interest' => $interest,
                            'plansUrl' => $plansUrl,
                            'acceptLocked' => $acceptLocked,
                            'containerClass' => 'mt-0',
                            'showLockedUpgradeHint' => false,
                        ])
                    </div>
                @endif
                @if ($useBlur && $acceptLocked && $interest->status === 'pending')
                    <p class="pt-0.5">
                        <a href="{{ $plansUrl }}" class="text-xs font-bold text-amber-200 underline decoration-amber-200/50 underline-offset-2 hover:text-white">
                            {{ __('who_viewed.teaser_photo_view_plans') }}
                        </a>
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
