@extends('layouts.app')

@section('content')
<div class="py-8">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">My biodata uploads</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">सर्व अपलोड केलेले बायोडाटा येथे दिसतील. स्टेटस पहा किंवा प्रीव्ह्यू / अप्रूव्ह करा.</p>
                </div>
                <a href="{{ route('intake.upload') }}" class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition whitespace-nowrap">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                    Upload new biodata
                </a>
            </div>

            @if (session('success'))
                <div class="mx-6 mt-4 px-4 py-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 text-emerald-800 dark:text-emerald-200 text-sm">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="mx-6 mt-4 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 text-sm">{{ session('error') }}</div>
            @endif

            <div class="overflow-x-auto">
                @if($intakes->isEmpty())
                    <div class="px-6 py-16 text-center">
                        <svg class="mx-auto h-14 w-14 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <h3 class="mt-4 text-lg font-medium text-gray-900 dark:text-gray-100">No biodata uploads yet</h3>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 max-w-sm mx-auto">बायोडाटा PDF किंवा टेक्स्ट अपलोड केल्यावर ते येथे दिसेल. तुम्ही स्टेटस आणि प्रीव्ह्यू पाहू शकता.</p>
                        <a href="{{ route('intake.upload') }}" class="mt-6 inline-flex items-center px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">
                            Upload biodata
                        </a>
                    </div>
                @else
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                        <thead class="bg-gray-50 dark:bg-gray-700/50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Uploaded</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">File / Source</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Parse status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Approved</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-600">
                            @foreach($intakes as $intake)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                        {{ $intake->created_at->format('d M Y, H:i') }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                        {{ $intake->original_filename ?: 'Pasted text' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($intake->parse_status === 'pending')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Pending</span>
                                        @elseif($intake->parse_status === 'parsed')
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">Parsed</span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200">Failed</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        @if($intake->approved_by_user)
                                            <span class="text-emerald-600 dark:text-emerald-400 font-medium">Yes</span>
                                        @else
                                            <span class="text-gray-500 dark:text-gray-400">No</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <a href="{{ route('intake.status', $intake) }}" class="text-red-600 dark:text-red-400 hover:underline font-medium">Status</a>
                                        @if($intake->parse_status === 'parsed' && !$intake->approved_by_user)
                                            <span class="mx-1 text-gray-300 dark:text-gray-600">|</span>
                                            <a href="{{ route('intake.preview', $intake) }}" class="text-red-600 dark:text-red-400 hover:underline font-medium">Preview &amp; approve</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if($intakes->isNotEmpty())
                <div class="px-6 py-3 bg-gray-50 dark:bg-gray-700/30 border-t border-gray-200 dark:border-gray-600 text-sm text-gray-600 dark:text-gray-400">
                    <a href="{{ route('dashboard') }}" class="hover:text-red-600 dark:hover:text-red-400">← Back to Dashboard</a>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
