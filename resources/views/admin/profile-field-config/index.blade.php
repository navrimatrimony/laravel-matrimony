@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Profile Field Configuration</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Manage field flags: enabled, visible, searchable, mandatory. Changes affect completeness calculation and search filters.</p>
    
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif

    <div class="mb-6 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-sky-800 dark:text-sky-200 text-sm">
        <p class="font-semibold mb-1">Field Configuration Rules</p>
        <ul class="list-disc pl-5 space-y-0.5">
            <li><strong>Enabled:</strong> Field is active in the system</li>
            <li><strong>Visible:</strong> Field is shown to users (future enforcement)</li>
            <li><strong>Searchable:</strong> Field can be used in search filters</li>
            <li><strong>Mandatory:</strong> Field is required for profile completeness calculation</li>
        </ul>
    </div>

    <form method="POST" action="{{ route('admin.profile-field-config.update') }}" class="space-y-6">
        @csrf

        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50">
                        <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Field Key</th>
                        <th class="text-center py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Enabled</th>
                        <th class="text-center py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Visible</th>
                        <th class="text-center py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Searchable</th>
                        <th class="text-center py-3 px-4 font-semibold text-gray-700 dark:text-gray-300">Mandatory</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fieldConfigs as $config)
                        <tr class="border-b border-gray-100 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="py-3 px-4">
                                <strong class="text-gray-800 dark:text-gray-200">{{ $config->field_key }}</strong>
                                <input type="hidden" name="fields[{{ $loop->index }}][id]" value="{{ $config->id }}">
                            </td>
                            <td class="py-3 px-4 text-center">
                                <input type="checkbox" 
                                       name="fields[{{ $loop->index }}][is_enabled]" 
                                       value="1" 
                                       {{ $config->is_enabled ? 'checked' : '' }}
                                       class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                            </td>
                            <td class="py-3 px-4 text-center">
                                <input type="checkbox" 
                                       name="fields[{{ $loop->index }}][is_visible]" 
                                       value="1" 
                                       {{ $config->is_visible ? 'checked' : '' }}
                                       class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                            </td>
                            <td class="py-3 px-4 text-center">
                                <input type="checkbox" 
                                       name="fields[{{ $loop->index }}][is_searchable]" 
                                       value="1" 
                                       {{ $config->is_searchable ? 'checked' : '' }}
                                       class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                            </td>
                            <td class="py-3 px-4 text-center">
                                <input type="checkbox" 
                                       name="fields[{{ $loop->index }}][is_mandatory]" 
                                       value="1" 
                                       {{ $config->is_mandatory ? 'checked' : '' }}
                                       class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Reason for changes <span class="text-red-600">*</span>
            </label>
            <textarea name="reason" 
                      rows="3" 
                      required 
                      placeholder="Explain why you are changing these field configurations (minimum 10 characters)"
                      class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">{{ old('reason') }}</textarea>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">This reason will be logged in the audit log.</p>
        </div>

        <div class="pt-4">
            <button type="submit" style="background-color: #4f46e5; color: white; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 16px; border: none; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                Save Field Configuration
            </button>
        </div>
    </form>
</div>
@endsection
