{{-- Shaadi.com-aligned Step 2: Education, profession, income, height/weight/body type, family. --}}
<div class="space-y-6">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-2">Personal and family</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Highest Education</label>
            <input type="text" name="highest_education" value="{{ old('highest_education', $profile->highest_education) }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Specialization</label>
            <input type="text" name="specialization" value="{{ old('specialization', $profile->specialization) }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Occupation Title</label>
            <input type="text" name="occupation_title" value="{{ old('occupation_title', $profile->occupation_title) }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Company Name</label>
            <input type="text" name="company_name" value="{{ old('company_name', $profile->company_name) }}" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Annual Income</label>
            <input type="number" name="annual_income" value="{{ old('annual_income', $profile->annual_income) }}" step="0.01" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Family Income</label>
            <input type="number" name="family_income" value="{{ old('family_income', $profile->family_income) }}" step="0.01" min="0" class="w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Income Currency</label>
            <select name="income_currency_id" class="form-select w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 px-3 py-2">
                @php $defaultCurrencyId = collect($currencies ?? [])->firstWhere('is_default', true)?->id; @endphp
                @foreach($currencies ?? [] as $currency)
                    <option value="{{ $currency->id }}" {{ old('income_currency_id', $profile->income_currency_id ?? $defaultCurrencyId) == $currency->id ? 'selected' : '' }}>{{ $currency->symbol }} {{ $currency->code }}</option>
                @endforeach
            </select>
        </div>
        {{-- Parent engine: Father + Mother core details, extra info, contact numbers, and parents address. --}}
        <div class="md:col-span-2">
            <x-parent-engine :profile="$profile" />
        </div>
        {{-- Family Overview engine: border is inside component. --}}
        <div class="md:col-span-2">
            <x-family-overview :profile="$profile" />
        </div>
        {{-- Weight and Body type are in the Physical section (Physical Engine). --}}
    </div>
    <div id="children-section">
        <p class="text-sm text-gray-500 dark:text-gray-400">Add children, education and career history from Full Edit after completing the wizard.</p>
    </div>
</div>
