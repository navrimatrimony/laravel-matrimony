@extends('layouts.admin')

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <a href="{{ route('admin.suchak.accounts.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Back to Suchak accounts</a>
                <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $suchakAccount->suchak_name }}</h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ ucfirst($suchakAccount->business_type) }} account requested by {{ $suchakAccount->user?->email }}</p>
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
                    <form method="POST" action="{{ route('admin.suchak.accounts.suspend', $suchakAccount) }}" class="space-y-2">
                        @csrf
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Suspend reason</label>
                        <textarea name="reason" rows="2" required minlength="10" maxlength="500" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('reason') }}</textarea>
                        <button type="submit" class="w-full rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">Suspend</button>
                    </form>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-300">No Day-4 action is available for this verification status.</p>
                @endif
            </div>
        </section>
    </div>

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Verification Records</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Type</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Status</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Admin</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Remarks</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($suchakAccount->verificationRecords as $record)
                        <tr>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ ucfirst($record->verification_type) }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ ucfirst($record->admin_status) }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $record->adminUser?->email ?: '-' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $record->remarks ?: '-' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $record->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-5 text-center text-gray-500 dark:text-gray-400">No verification records yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
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
