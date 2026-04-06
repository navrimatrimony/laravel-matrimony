@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('admin_commerce.coupons_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin_commerce.coupons_intro') }}</p>
        </div>
        <a href="{{ route('admin.commerce.coupons.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
            {{ __('admin_commerce.coupon_create') }}
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm text-left">
            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                <tr>
                    <th class="py-3 pr-4">Code</th>
                    <th class="py-3 pr-4">Type</th>
                    <th class="py-3 pr-4">Value</th>
                    <th class="py-3 pr-4">Redemptions</th>
                    <th class="py-3 pr-4">Until</th>
                    <th class="py-3 pr-4">Active</th>
                    <th class="py-3 pr-4"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($coupons as $c)
                    <tr>
                        <td class="py-3 pr-4 font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $c->code }}</td>
                        <td class="py-3 pr-4">{{ $c->type }}</td>
                        <td class="py-3 pr-4">{{ $c->type === \App\Models\Coupon::TYPE_PERCENT ? $c->value.'%' : '₹'.$c->value }}</td>
                        <td class="py-3 pr-4 tabular-nums">{{ $c->redemptions_count }}{{ $c->max_redemptions !== null ? ' / '.$c->max_redemptions : '' }}</td>
                        <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $c->valid_until?->format('Y-m-d') ?? '—' }}</td>
                        <td class="py-3 pr-4">{{ $c->is_active ? 'Yes' : 'No' }}</td>
                        <td class="py-3 pr-4 whitespace-nowrap">
                            <a href="{{ route('admin.commerce.coupons.edit', $c) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 mr-3">Edit</a>
                            <form action="{{ route('admin.commerce.coupons.destroy', $c) }}" method="POST" class="inline" onsubmit="return confirm('Delete this coupon?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:text-red-800 dark:text-red-400 text-xs font-semibold">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $coupons->links() }}</div>
</div>
@endsection
