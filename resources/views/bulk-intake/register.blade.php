<x-guest-layout>
@php
    $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
    $heightCm = $payload['height_cm'] ?? null;
    $genders = is_array($payload['genders'] ?? null) ? $payload['genders'] : [];
    $motherTongues = is_array($payload['mother_tongues'] ?? null) ? $payload['mother_tongues'] : [];
    $maritalStatuses = is_array($payload['marital_statuses'] ?? null) ? $payload['marital_statuses'] : [];
    $religions = is_array($payload['religions'] ?? null) ? $payload['religions'] : [];
    $castes = is_array($payload['castes'] ?? null) ? $payload['castes'] : [];
    $workingWithOptions = is_array($payload['working_with_options'] ?? null) ? $payload['working_with_options'] : [];
    $registrationComplete = (bool) ($payload['registration_complete'] ?? false);
    $candidateName = is_string($payload['candidate_name'] ?? null) ? $payload['candidate_name'] : null;
@endphp

<div class="mx-auto max-w-3xl px-4 py-8">
    <div class="rounded-2xl border border-violet-200 bg-white p-6 shadow-sm">
        <h1 class="text-2xl font-bold text-gray-900">बायोडाटा नोंदणी पुष्टी</h1>
        <p class="mt-2 text-sm text-gray-600">
            खालील माहिती तपासा आणि बरोबर असल्यास जतन करा. उंची फूट/इंच मध्ये दिसेल; आम्ही ती सेमीमध्ये जतन करतो.
        </p>
        @if ($candidateName)
            <p class="mt-1 text-sm font-medium text-violet-800">{{ $candidateName }}</p>
        @endif

        @if (session('success'))
            <div class="mt-4 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('success') }}</div>
        @endif

        @if ($registrationComplete)
            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                नोंदणी पूर्ण झाली आहे. गरज असल्यास खाली माहिती पुन्हा बदलू शकता.
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('bulk-intake.register.store', ['token' => $token]) }}" class="mt-6 space-y-5">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">नाव</label>
                    <input type="text" name="full_name" value="{{ old('full_name', $fields['full_name'] ?? '') }}" required class="w-full rounded-lg border-gray-300">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">मोबाईल</label>
                    <input type="tel" name="mobile" value="{{ old('mobile', $fields['mobile'] ?? '') }}" required class="w-full rounded-lg border-gray-300">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">जन्मतारीख</label>
                    <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $fields['date_of_birth'] ?? '') }}" required class="w-full rounded-lg border-gray-300">
                </div>

                <div class="sm:col-span-2">
                    <x-profile.height-picker
                        :value="old('height_cm', $heightCm)"
                        label="उंची (फूट/इंच)"
                        :required="true"
                    />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">लिंग</label>
                    <select name="gender" required class="w-full rounded-lg border-gray-300">
                        <option value="">निवडा</option>
                        @foreach ($genders as $gender)
                            <option value="{{ $gender['key'] ?? $gender['id'] }}" @selected((string) old('gender', $fields['gender'] ?? '') === (string) ($gender['key'] ?? $gender['id']))>
                                {{ $gender['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">मातृभाषा</label>
                    <select name="mother_tongue_id" required class="w-full rounded-lg border-gray-300">
                        <option value="">निवडा</option>
                        @foreach ($motherTongues as $option)
                            <option value="{{ $option['id'] }}" @selected((string) old('mother_tongue_id', $fields['mother_tongue_id'] ?? '') === (string) $option['id'])>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">वैवाहिक स्थिती</label>
                    <select name="marital_status_id" required class="w-full rounded-lg border-gray-300">
                        <option value="">निवडा</option>
                        @foreach ($maritalStatuses as $option)
                            <option value="{{ $option['id'] }}" @selected((string) old('marital_status_id', $fields['marital_status_id'] ?? '') === (string) $option['id'])>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">धर्म</label>
                    <select name="religion_id" required class="w-full rounded-lg border-gray-300">
                        <option value="">निवडा</option>
                        @foreach ($religions as $option)
                            <option value="{{ $option['id'] }}" @selected((string) old('religion_id', $fields['religion_id'] ?? '') === (string) $option['id'])>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">जात</label>
                    <select name="caste_id" required class="w-full rounded-lg border-gray-300">
                        <option value="">निवडा</option>
                        @foreach ($castes as $option)
                            <option value="{{ $option['id'] }}" @selected((string) old('caste_id', $fields['caste_id'] ?? '') === (string) $option['id'])>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">ठिकाण</label>
                    <input type="text" name="location" value="{{ old('location', $fields['location'] ?? '') }}" required class="w-full rounded-lg border-gray-300">
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">शिक्षण</label>
                    <input type="text" name="education" value="{{ old('education', $fields['education'] ?? '') }}" required class="w-full rounded-lg border-gray-300">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">कामाचा प्रकार</label>
                    <select name="working_with" required class="w-full rounded-lg border-gray-300">
                        <option value="">निवडा</option>
                        @foreach ($workingWithOptions as $option)
                            <option value="{{ $option['value'] }}" @selected((string) old('working_with', $fields['working_with'] ?? '') === (string) $option['value'])>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">व्यवसाय</label>
                    <input type="text" name="occupation" value="{{ old('occupation', $fields['occupation'] ?? '') }}" class="w-full rounded-lg border-gray-300">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">कंपनी (ऐच्छिक)</label>
                    <input type="text" name="company_name" value="{{ old('company_name', $fields['company_name'] ?? '') }}" class="w-full rounded-lg border-gray-300">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">उत्पन्न (ऐच्छिक)</label>
                    <input type="text" name="annual_income" value="{{ old('annual_income', $fields['annual_income'] ?? '') }}" class="w-full rounded-lg border-gray-300">
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="inline-flex rounded-lg bg-violet-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-violet-800">
                    नोंदणी माहिती जतन करा
                </button>
            </div>
        </form>
    </div>
</div>
</x-guest-layout>
