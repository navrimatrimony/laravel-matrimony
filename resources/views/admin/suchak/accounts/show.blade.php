@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <a href="{{ route('admin.suchak.accounts.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Back to Suchak accounts</a>
                <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $suchakAccount->suchak_name }}</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ ucfirst($suchakAccount->business_type) }} account requested by {{ $suchakAccount->user?->email }}</p>
                <a href="{{ route('admin.suchak.safety.index') }}" class="mt-3 inline-flex rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">Open safety center</a>
            </div>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-md bg-gray-50 px-4 py-3 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Verification</div>
                    <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ ucfirst($suchakAccount->verification_status) }}</div>
                </div>
                <div class="rounded-md bg-gray-50 px-4 py-3 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Public</div>
                    <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ ucfirst($suchakAccount->public_status) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800 lg:col-span-2">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Account Details</h2>
            <dl class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Office</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $suchakAccount->office_name ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Mobile</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $suchakAccount->mobile_number ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Mobile OTP</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                        {{ $suchakAccount->user?->mobile_verified_at ? 'Verified' : 'Not verified' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">WhatsApp</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $suchakAccount->whatsapp_number ?: '-' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Email</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $suchakAccount->email ?: '-' }}</dd>
                </div>
                <div class="md:col-span-2">
                    <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Address</dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $suchakAccount->address_line ?: '-' }}</dd>
                </div>
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

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Plan & Entitlement Assignment</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Assign an active Suchak plan manually. No member subscription or payment execution is triggered here.</p>
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
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Verification Records</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Type</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Status</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Document</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Admin</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Remarks</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Created</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Review</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($suchakAccount->verificationRecords as $record)
                        <tr>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ ucfirst($record->verification_type) }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ ucfirst($record->admin_status) }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $record->document_path ?: '-' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $record->adminUser?->email ?: '-' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $record->remarks ?: '-' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $record->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">
                                @if ($record->admin_status === \App\Models\SuchakVerificationRecord::STATUS_PENDING)
                                    <div class="space-y-2">
                                        <form method="POST" action="{{ route('admin.suchak.accounts.verification-records.approve', [$suchakAccount, $record]) }}" class="space-y-1">
                                            @csrf
                                            <textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="Approval reason" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                                            <button type="submit" class="rounded-md bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Approve record</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.suchak.accounts.verification-records.reject', [$suchakAccount, $record]) }}" class="space-y-1">
                                            @csrf
                                            <textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="Reject reason" class="w-full rounded-md border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                                            <button type="submit" class="rounded-md bg-red-600 px-2 py-1 text-xs font-semibold text-white hover:bg-red-700">Reject record</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Reviewed</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-5 text-center text-gray-500 dark:text-gray-400">No verification records yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Consent Evidence</h2>
        <div class="mt-4 space-y-4">
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
                            <dt class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">OTP evidence</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $consent->otp_hash ? 'Hashed OTP stored' : 'No OTP hash' }}</dd>
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
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Suchak Activity</h2>
        <div class="mt-4 space-y-3">
            @forelse ($activityLogs as $activity)
                <div class="rounded-md border border-gray-200 p-3 text-sm dark:border-gray-700">
                    <div class="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $activity->action_type }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $activity->occurred_at?->format('Y-m-d H:i') }}</div>
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Actor: {{ $activity->actor_type }}{{ $activity->actorUser?->email ? ' / '.$activity->actorUser->email : '' }}
                        @if ($activity->admin_audit_log_id)
                            | Admin audit #{{ $activity->admin_audit_log_id }}
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No activity logged yet.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
