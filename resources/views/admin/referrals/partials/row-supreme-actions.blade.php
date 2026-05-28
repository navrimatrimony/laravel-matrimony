@php
    $returnTab = $returnTab ?? 'reports';
    $referrerLookup = $referrerLookup ?? '';
@endphp
<div class="mt-2 space-y-2 border-t border-gray-200 dark:border-gray-600 pt-2 text-xs">
    @if (! $referral->reward_applied)
        <form method="POST" action="{{ route('admin.referrals.reassign', $referral) }}" class="flex flex-wrap items-end gap-1">
            @csrf
            <input type="hidden" name="return_tab" value="{{ $returnTab }}">
            @if ($referrerLookup !== '')
                <input type="hidden" name="referrer_lookup" value="{{ $referrerLookup }}">
            @endif
            <label class="sr-only">{{ __('admin_monetization.referral_reassign_new_referrer') }}</label>
            <input type="number" name="new_referrer_id" min="1" required placeholder="{{ __('admin_monetization.referral_reassign_new_referrer') }}" class="w-24 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
            <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="{{ __('admin_monetization.referral_reassign_reason') }}" class="min-w-[8rem] flex-1 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
            <button type="submit" class="rounded bg-indigo-600 px-2 py-1 font-semibold text-white">{{ __('admin_monetization.referral_reassign_btn') }}</button>
        </form>
    @endif

    <form method="POST" action="{{ route('admin.referrals.partial-reward', $referral) }}" class="space-y-1">
        <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin_monetization.referral_partial_form_title') }}</p>
        <div class="grid grid-cols-3 sm:grid-cols-4 gap-1 items-end">
        @csrf
        <input type="hidden" name="return_tab" value="{{ $returnTab }}">
        @if ($referrerLookup !== '')
            <input type="hidden" name="referrer_lookup" value="{{ $referrerLookup }}">
        @endif
        <div>
            <label class="block text-[10px] text-gray-500 dark:text-gray-400 mb-0.5">{{ __('admin_monetization.referral_partial_days') }}</label>
            <input type="number" name="bonus_days" min="0" value="0" class="w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
        </div>
        <div>
            <label class="block text-[10px] text-gray-500 dark:text-gray-400 mb-0.5">{{ __('admin_monetization.referral_partial_chat') }}</label>
            <input type="number" name="chat_send_limit_bonus" min="0" value="0" class="w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
        </div>
        <div>
            <label class="block text-[10px] text-gray-500 dark:text-gray-400 mb-0.5">{{ __('admin_monetization.referral_partial_contact') }}</label>
            <input type="number" name="contact_view_limit_bonus" min="0" value="0" class="w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
        </div>
        <label class="inline-flex items-center gap-1 col-span-2 sm:col-span-1 text-gray-600 dark:text-gray-300">
            <input type="hidden" name="mark_reward_applied" value="0">
            <input type="checkbox" name="mark_reward_applied" value="1" @checked($referral->reward_applied) @disabled($referral->reward_applied) class="rounded border-gray-300">
            {{ __('admin_monetization.referral_partial_mark_applied') }}
        </label>
        <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="{{ __('admin_monetization.referral_partial_reason') }}" class="col-span-2 sm:col-span-3 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
        <button type="submit" class="col-span-3 sm:col-span-1 rounded bg-violet-600 px-2 py-1 font-semibold text-white">{{ __('admin_monetization.referral_partial_btn') }}</button>
        </div>
        <p class="text-[10px] text-gray-500 dark:text-gray-400">{{ __('admin_monetization.referral_partial_form_hint') }}</p>
    </form>

    @if ($referral->reward_applied)
        <form method="POST" action="{{ route('admin.referrals.revoke-reward', $referral) }}" onsubmit="return confirm('{{ __('admin_monetization.referral_revoke_confirm') }}');" class="flex flex-wrap items-end gap-1">
            @csrf
            <input type="hidden" name="return_tab" value="{{ $returnTab }}">
            @if ($referrerLookup !== '')
                <input type="hidden" name="referrer_lookup" value="{{ $referrerLookup }}">
            @endif
            <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="{{ __('admin_monetization.referral_revoke_reason') }}" class="min-w-[8rem] flex-1 rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
            <button type="submit" class="rounded bg-rose-700 px-2 py-1 font-semibold text-white">{{ __('admin_monetization.referral_revoke_btn') }}</button>
        </form>
    @endif
</div>
