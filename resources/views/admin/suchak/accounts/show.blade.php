@extends('layouts.admin')

@php
    use App\Models\SuchakAccount;
    use App\Models\SuchakVerificationRecord;
    use App\Support\Suchak\SuchakOnboardingPresenter;

    // Same requirement rule the queue and the app use — not restated here.
    $requiredDocTypes = array_keys(array_filter(
        SuchakOnboardingPresenter::documentRequirements((string) $suchakAccount->business_type)
    ));
    $approvedDocTypes = $suchakAccount->verificationRecords
        ->where('admin_status', SuchakVerificationRecord::STATUS_APPROVED)
        ->pluck('verification_type')
        ->all();
    $docsApproved = count(array_intersect($requiredDocTypes, $approvedDocTypes));
    $docsRequired = count($requiredDocTypes);
    $docsComplete = $docsApproved >= $docsRequired;

    $isPending = $suchakAccount->verification_status === SuchakAccount::VERIFICATION_PENDING;
    $waitingDays = $suchakAccount->created_at
        ? (int) $suchakAccount->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay())
        : 0;

    $place = $suchakAccount->cityLocation?->name ?? $suchakAccount->districtLocation?->name;
@endphp

@section('content')
<div class="space-y-6">
    {{-- Header carries the whole verdict: who, and the three things that decide
         whether this account can be approved. --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <a href="{{ route('admin.suchak.accounts.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">← Back to queue</a>
                <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {{ $suchakAccount->suchak_name ?: 'No name yet' }}
                </h1>
                {{-- Identity on one line instead of a details grid full of dashes. --}}
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $suchakAccount->mobile_number ?: 'No mobile' }}
                    @if ($place) · {{ $place }} @endif
                    · {{ ucfirst($suchakAccount->business_type) }}
                    @if ($suchakAccount->office_name) · {{ $suchakAccount->office_name }} @endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold ring-1 ring-inset
                    @if ($suchakAccount->verification_status === SuchakAccount::VERIFICATION_VERIFIED) bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300
                    @elseif ($suchakAccount->verification_status === SuchakAccount::VERIFICATION_REJECTED) bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300
                    @elseif ($suchakAccount->verification_status === SuchakAccount::VERIFICATION_SUSPENDED) bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-300
                    @else bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 @endif">
                    {{ ucfirst($suchakAccount->verification_status) }}
                </span>

                {{-- Approving the account and approving its documents are different
                     things; the detail page used to hide this three screens down. --}}
                <a href="#verification-records"
                   class="inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold ring-1 ring-inset {{ $docsComplete ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300' }}">
                    Docs {{ $docsApproved }}/{{ $docsRequired }}{{ $docsComplete ? ' ✓' : '' }}
                </a>

                @if ($isPending)
                    <span class="inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold ring-1 ring-inset {{ $waitingDays >= 3 ? 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-gray-100 text-gray-700 ring-gray-500/20 dark:bg-gray-700 dark:text-gray-200' }}">
                        {{ $waitingDays === 0 ? 'Today' : $waitingDays.'d waiting' }}
                    </span>
                @elseif ($suchakAccount->public_status !== SuchakAccount::PUBLIC_ACTIVE)
                    <span class="inline-flex items-center rounded-full bg-orange-50 px-3 py-1.5 text-sm font-semibold text-orange-700 ring-1 ring-inset ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-300">
                        Not public
                    </span>
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Account Details</h2>
            {{-- Only fields that actually hold something. A grid of "-" told the
                 reviewer nothing and pushed the real work further down. --}}
            @php
                $whatsappSameAsMobile = $suchakAccount->whatsapp_number
                    && $suchakAccount->whatsapp_number === $suchakAccount->mobile_number;
                $details = array_filter([
                    'Office' => $suchakAccount->office_name,
                    'Mobile' => $suchakAccount->mobile_number
                        ? $suchakAccount->mobile_number.($whatsappSameAsMobile ? ' (WhatsApp too)' : '')
                        : null,
                    'WhatsApp' => $whatsappSameAsMobile ? null : $suchakAccount->whatsapp_number,
                    'Mobile OTP' => $suchakAccount->user?->mobile_verified_at ? 'Verified' : 'Not verified',
                    'Email' => $suchakAccount->email,
                    'Address' => $suchakAccount->address_line,
                ]);
            @endphp
            <dl class="mt-4 grid gap-4 md:grid-cols-2">
                @foreach ($details as $label => $value)
                    <div @class(['md:col-span-2' => $label === 'Address'])>
                        <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Admin Actions</h2>
            <div class="mt-4 space-y-5">
                @if ($suchakAccount->verification_status === \App\Models\SuchakAccount::VERIFICATION_PENDING)
                    <form method="POST" action="{{ route('admin.suchak.accounts.approve', $suchakAccount) }}" class="space-y-2">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Approve reason</label>
                        <textarea name="reason" rows="2" required minlength="10" maxlength="500" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                        <button type="submit" class="w-full rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Approve</button>
                    </form>

                    <form method="POST" action="{{ route('admin.suchak.accounts.reject', $suchakAccount) }}" class="space-y-2">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reject reason</label>
                        <textarea name="reason" rows="2" required minlength="10" maxlength="500" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                        <button type="submit" class="w-full rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">Reject</button>
                    </form>
                @elseif ($suchakAccount->verification_status === \App\Models\SuchakAccount::VERIFICATION_VERIFIED)
                    <form method="POST" action="{{ route('admin.suchak.accounts.public-status.update', $suchakAccount) }}" class="space-y-2">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Public status</label>
                        <select name="public_status" required class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            <option value="{{ \App\Models\SuchakAccount::PUBLIC_HIDDEN }}" @selected($suchakAccount->public_status === \App\Models\SuchakAccount::PUBLIC_HIDDEN)>Hidden</option>
                            <option value="{{ \App\Models\SuchakAccount::PUBLIC_ACTIVE }}" @selected($suchakAccount->public_status === \App\Models\SuchakAccount::PUBLIC_ACTIVE)>Active</option>
                            <option value="{{ \App\Models\SuchakAccount::PUBLIC_INACTIVE }}" @selected($suchakAccount->public_status === \App\Models\SuchakAccount::PUBLIC_INACTIVE)>Inactive</option>
                        </select>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Public status reason</label>
                        <textarea name="reason" rows="2" required minlength="10" maxlength="500" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                        <button type="submit" class="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Update public status</button>
                    </form>

                    {{-- Suspend and Archive are rare and destructive. They used to
                         sit at the top of the page in loud colours while the daily
                         work was three screens below. Folded away by default. --}}
                    <details class="rounded-md border border-gray-200 dark:border-gray-700">
                        <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300">More actions</summary>
                        <div class="space-y-3 border-t border-gray-200 p-3 dark:border-gray-700">
                            <form method="POST" action="{{ route('admin.suchak.accounts.suspend', $suchakAccount) }}" class="space-y-2">
                                @csrf
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Suspend reason</label>
                                <textarea name="reason" rows="2" required minlength="10" maxlength="500" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                                <button type="submit" class="w-full rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">Suspend</button>
                            </form>

                            <form method="POST" action="{{ route('admin.suchak.accounts.archive', $suchakAccount) }}" class="space-y-2">
                                @csrf
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Archive reason</label>
                                <textarea name="reason" rows="2" required minlength="10" maxlength="500" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                                <button type="submit" class="w-full rounded-md bg-gray-700 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800">Archive</button>
                            </form>

                            <a href="{{ route('admin.suchak.safety.index') }}" class="block text-center text-sm font-medium text-gray-600 underline dark:text-gray-300">Open safety center</a>
                        </div>
                    </details>
                @elseif ($suchakAccount->verification_status === \App\Models\SuchakAccount::VERIFICATION_SUSPENDED)
                    <form method="POST" action="{{ route('admin.suchak.accounts.reactivate', $suchakAccount) }}" class="space-y-2">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reactivate reason</label>
                        <textarea name="reason" rows="2" required minlength="10" maxlength="500" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                        <button type="submit" class="w-full rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Reactivate</button>
                    </form>

                    <form method="POST" action="{{ route('admin.suchak.accounts.archive', $suchakAccount) }}" class="space-y-2">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Archive reason</label>
                        <textarea name="reason" rows="2" required minlength="10" maxlength="500" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                        <button type="submit" class="w-full rounded-md bg-gray-700 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800">Archive</button>
                    </form>
                @elseif (in_array($suchakAccount->verification_status, [\App\Models\SuchakAccount::VERIFICATION_REJECTED, \App\Models\SuchakAccount::VERIFICATION_ARCHIVED], true))
                    <form method="POST" action="{{ route('admin.suchak.accounts.reactivate', $suchakAccount) }}" class="space-y-2">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reopen for review reason</label>
                        <textarea name="reason" rows="2" required minlength="10" maxlength="500" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                        <button type="submit" class="w-full rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Reopen for review</button>
                    </form>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-300">No admin action is available for this verification status.</p>
                @endif
            </div>
        </section>
    </div>

    {{-- Plan assignment is occasional work; it does not belong in the middle of
         a verification review. Folded unless a plan is already assigned. --}}
    <details class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800" @if ($activeSubscription?->suchakPlan) open @endif>
        <summary class="cursor-pointer px-6 py-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
            Plan &amp; Entitlement
            <span class="ml-2 text-sm font-normal text-gray-500 dark:text-gray-400">
                {{ $activeSubscription?->suchakPlan?->name ?? 'none assigned' }}
            </span>
        </summary>
        <div class="border-t border-gray-200 p-6 dark:border-gray-700">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-300">Assign an active Suchak plan manually. No member subscription or payment execution is triggered here.</p>
            </div>
            <a href="{{ route('admin.suchak.plans.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Manage plans</a>
        </div>

        <div class="mt-5 grid gap-5 lg:grid-cols-[1fr_2fr]">
            <div class="rounded-md bg-gray-50 p-4 text-sm dark:bg-gray-900">
                <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Current active plan</p>
                @if ($activeSubscription?->suchakPlan)
                    <p class="mt-2 font-semibold text-gray-900 dark:text-gray-100">{{ $activeSubscription->suchakPlan->name }}</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-300">
                        Starts: {{ $activeSubscription->starts_at?->format('Y-m-d H:i') ?: '-' }}
                        <br>
                        Ends: {{ $activeSubscription->ends_at?->format('Y-m-d H:i') ?: 'No end date' }}
                    </p>
                @else
                    <p class="mt-2 text-gray-600 dark:text-gray-300">No active Suchak plan is assigned.</p>
                @endif
            </div>

            <form method="POST" action="{{ route('admin.suchak.plans.accounts.assign', $suchakAccount) }}" class="grid gap-4 md:grid-cols-2">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="suchak_plan_id">Plan</label>
                    <select id="suchak_plan_id" name="suchak_plan_id" required class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">Select plan</option>
                        @foreach ($assignablePlans as $plan)
                            <option value="{{ $plan->id }}" @selected((int) old('suchak_plan_id') === (int) $plan->id)>
                                {{ $plan->name }} - {{ $plan->hasConfiguredPrice() ? $plan->currency.' '.$plan->price_amount : 'manual price' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="starts_at">Starts at</label>
                    <input id="starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="ends_at">Ends at</label>
                    <input id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at') }}" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="plan_assign_reason">Assignment reason</label>
                    <textarea id="plan_assign_reason" name="reason" rows="2" required minlength="10" maxlength="500" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Assign Suchak plan</button>
                </div>
            </form>
        </div>
        </div>
    </details>

    <section id="verification-records" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Verification Records</h2>
            <span class="rounded-full px-3 py-1 text-sm font-semibold ring-1 ring-inset {{ $docsComplete ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300' }}">
                {{ $docsApproved }}/{{ $docsRequired }} required approved
            </span>
        </div>
        {{-- One card per document. The old table kept two textareas and two
             buttons open on every row at once, which buried the decision itself;
             the reason is now asked only after choosing approve or reject, and a
             thumbnail lets the reviewer judge without leaving the page. --}}
        <div class="mt-4 space-y-3">
            @forelse ($suchakAccount->verificationRecords as $record)
                @php
                    $isRecordPending = $record->admin_status === SuchakVerificationRecord::STATUS_PENDING;
                    $recordTone = match ($record->admin_status) {
                        SuchakVerificationRecord::STATUS_APPROVED => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
                        SuchakVerificationRecord::STATUS_REJECTED => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300',
                        default => 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300',
                    };
                    $docUrl = $record->document_path
                        ? route('admin.suchak.accounts.verification-records.document', [$suchakAccount, $record])
                        : null;
                @endphp
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($docUrl)
                            <a href="{{ $docUrl }}" target="_blank" rel="noopener" class="shrink-0">
                                <img src="{{ $docUrl }}" alt="" class="h-14 w-14 rounded-md object-cover ring-1 ring-gray-200 dark:ring-gray-600">
                            </a>
                        @else
                            <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-md bg-gray-100 text-xs text-gray-400 dark:bg-gray-700">none</span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ ucfirst(str_replace('_', ' ', $record->verification_type)) }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $recordTone }}">{{ ucfirst($record->admin_status) }}</span>
                                @if (in_array($record->verification_type, $requiredDocTypes, true))
                                    <span class="text-xs text-gray-500 dark:text-gray-400">required</span>
                                @endif
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $record->created_at?->diffForHumans() }}@if ($record->adminUser?->email) · reviewed by {{ $record->adminUser->email }}@endif
                            </div>
                            @if ($record->remarks)
                                <div class="mt-1 text-xs text-gray-700 dark:text-gray-300">{{ $record->remarks }}</div>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            @if ($docUrl)
                                <a href="{{ $docUrl }}" target="_blank" rel="noopener" class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200">Open</a>
                            @endif
                            @if ($isRecordPending)
                                {{-- Inline display toggle rather than a `hidden` class:
                                     Tailwind's sm:flex beats .hidden at >=640px, which
                                     left every reason field open — the exact clutter
                                     this redesign removes. --}}
                                <button type="button" onclick="(function(a,r){a.style.display=a.style.display==='flex'?'none':'flex';r.style.display='none';})(document.getElementById('rec-{{ $record->id }}-approve'),document.getElementById('rec-{{ $record->id }}-reject'))"
                                        class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Approve</button>
                                <button type="button" onclick="(function(a,r){r.style.display=r.style.display==='flex'?'none':'flex';a.style.display='none';})(document.getElementById('rec-{{ $record->id }}-approve'),document.getElementById('rec-{{ $record->id }}-reject'))"
                                        class="rounded-md border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-500/40 dark:text-rose-300">Reject</button>
                            @endif
                        </div>
                    </div>

                    @if ($isRecordPending)
                        <form id="rec-{{ $record->id }}-approve" style="display:none" method="POST" action="{{ route('admin.suchak.accounts.verification-records.approve', [$suchakAccount, $record]) }}" class="mt-3 gap-2">
                            @csrf
                            <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="Why is this approved? (min 10 characters)"
                                   class="flex-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Confirm approve</button>
                        </form>
                        <form id="rec-{{ $record->id }}-reject" style="display:none" method="POST" action="{{ route('admin.suchak.accounts.verification-records.reject', [$suchakAccount, $record]) }}" class="mt-3 gap-2">
                            @csrf
                            <input type="text" name="reason" required minlength="10" maxlength="500" placeholder="Why is this rejected? The Suchak sees this."
                                   class="flex-1 rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            <button type="submit" class="rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">Confirm reject</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">No documents submitted yet.</p>
            @endforelse
        </div>

    </section>

    {{-- Reference material, not review work — folded, with the count in the
         summary so an empty section costs one line instead of a whole card. --}}
    <details class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800" @if ($consentEvidence->isNotEmpty()) open @endif>
        <summary class="cursor-pointer px-6 py-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
            Consent Evidence
            <span class="ml-2 text-sm font-normal text-gray-500 dark:text-gray-400">({{ $consentEvidence->count() }})</span>
        </summary>
        <div class="space-y-4 border-t border-gray-200 p-6 dark:border-gray-700">
            @forelse ($consentEvidence as $consent)
                <article class="rounded-md border border-gray-200 p-4 text-sm dark:border-gray-700">
                    <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-gray-100">
                                Consent #{{ $consent->id }} · Representation #{{ $consent->representation_id }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ ucfirst(str_replace('_', ' ', $consent->consent_status)) }}
                                · {{ ucfirst(str_replace('_', ' ', $consent->consent_type)) }}
                                · {{ ucfirst(str_replace('_', ' ', $consent->consent_channel)) }}
                            </p>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            Created: {{ $consent->created_at?->format('Y-m-d H:i') }}
                        </div>
                    </div>

                    <dl class="mt-4 grid gap-3 md:grid-cols-3">
                        <div>
                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Consent giver</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $consent->consent_given_by_name ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Relationship</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $consent->relationship_to_candidate ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Mobile</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $consent->consent_mobile_number ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Valid from</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $consent->valid_from?->format('Y-m-d H:i') ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Valid until</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $consent->valid_until?->format('Y-m-d H:i') ?: 'Until revoked / not active' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Evidence type</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">
                                @if ($consent->proof_file_path)
                                    Signed proof file stored
                                @elseif ($consent->mobile_match)
                                    Accepted for requested mobile number
                                @else
                                    Secure request pending
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Token expiry</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $consent->token_expires_at?->format('Y-m-d H:i') ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Accepted</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $consent->accepted_at?->format('Y-m-d H:i') ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Revoked</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $consent->revoked_at?->format('Y-m-d H:i') ?: '-' }}</dd>
                        </div>
                        @if ($consent->revocation_reason)
                            <div class="md:col-span-3">
                                <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Revocation reason</dt>
                                <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $consent->revocation_reason }}</dd>
                            </div>
                        @endif
                    </dl>

                    <div class="mt-4 rounded-md bg-gray-50 p-3 dark:bg-gray-900">
                        <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Consent timeline</p>
                        <div class="mt-2 space-y-2">
                            @forelse ($consent->events as $event)
                                <div>
                                    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ ucfirst(str_replace('_', ' ', $event->event_type)) }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $event->created_at?->format('Y-m-d H:i') }}</span>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        Actor: {{ $event->actor_type }}{{ $event->actorUser?->email ? ' / '.$event->actorUser->email : '' }}
                                    </div>
                                    @if ($event->event_note)
                                        <div class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $event->event_note }}</div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-xs text-gray-500 dark:text-gray-400">No consent events recorded.</p>
                            @endforelse
                        </div>
                    </div>
                </article>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No consent evidence recorded yet.</p>
            @endforelse
        </div>
    </details>

    <details class="rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <summary class="cursor-pointer px-6 py-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
            Activity
            <span class="ml-2 text-sm font-normal text-gray-500 dark:text-gray-400">({{ $activityLogs->count() }})</span>
        </summary>
        <div class="space-y-2 border-t border-gray-200 p-6 dark:border-gray-700">
            @forelse ($activityLogs as $activity)
                @php
                    // Raw action keys like "suchak_onboarding_requested" are database
                    // words, not something a reviewer should have to decode.
                    $actionLabel = ucfirst(str_replace('_', ' ', (string) $activity->action_type));
                @endphp
                <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-100 pb-2 text-sm last:border-0 dark:border-gray-700">
                    <div>
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $actionLabel }}</span>
                        <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ $activity->actorUser?->email ?: $activity->actor_type }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400" title="{{ $activity->occurred_at?->format('Y-m-d H:i') }}">
                        {{ $activity->occurred_at?->diffForHumans() }}
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No activity logged yet.</p>
            @endforelse
        </div>
    </details>
</div>
@endsection
