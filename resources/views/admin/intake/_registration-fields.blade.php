<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label for="new_name" class="block text-sm font-medium text-gray-700">Registrant name</label>
        <input id="new_name" name="new_name" type="text" value="{{ old('new_name') }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div>
        <label for="new_mobile" class="block text-sm font-medium text-gray-700">Mobile</label>
        <input id="new_mobile" name="new_mobile" type="text" inputmode="numeric" value="{{ old('new_mobile') }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div>
        <label for="new_email" class="block text-sm font-medium text-gray-700">Email (optional)</label>
        <input id="new_email" name="new_email" type="email" value="{{ old('new_email') }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div>
        <label for="registering_for" class="block text-sm font-medium text-gray-700">Registering for</label>
        <select id="registering_for" name="registering_for" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            @foreach (['self' => 'Self', 'parent_guardian' => 'Parent / guardian', 'sibling' => 'Sibling', 'relative' => 'Relative', 'friend' => 'Friend', 'other' => 'Other'] as $value => $label)
                <option value="{{ $value }}" @selected(old('registering_for', 'self') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="new_gender" class="block text-sm font-medium text-gray-700">Gender</label>
        <select id="new_gender" name="new_gender" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">Select gender</option>
            @foreach ($genders as $gender)
                <option value="{{ $gender->key }}" @selected(old('new_gender') === $gender->key)>{{ $gender->label }}</option>
            @endforeach
        </select>
    </div>
    @if (! empty($registrationHint))
        <p class="sm:col-span-2 text-xs text-amber-700">{{ $registrationHint }}</p>
    @endif
</div>
