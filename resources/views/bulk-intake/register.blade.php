@extends('layouts.bulk-register')

@php
    $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
    $heightCm = $payload['height_cm'] ?? null;
    $genders = is_array($payload['genders'] ?? null) ? $payload['genders'] : [];
    $motherTongues = is_array($payload['mother_tongues'] ?? null) ? $payload['mother_tongues'] : [];
    $maritalStatuses = is_array($payload['marital_statuses'] ?? null) ? $payload['marital_statuses'] : [];
    $religions = is_array($payload['religions'] ?? null) ? $payload['religions'] : [];
    $castes = is_array($payload['castes'] ?? null) ? $payload['castes'] : [];
    $workingWithOptions = is_array($payload['working_with_options'] ?? null) ? $payload['working_with_options'] : [];
    $occupations = is_array($payload['occupations'] ?? null) ? $payload['occupations'] : [];
    $occupationExemptSlugs = is_array($payload['occupation_exempt_slugs'] ?? null) ? $payload['occupation_exempt_slugs'] : [];
    $candidatePhoto = is_array($payload['candidate_photo'] ?? null) ? $payload['candidate_photo'] : [];
    $registrationComplete = (bool) ($payload['registration_complete'] ?? false);
    $candidateName = is_string($payload['candidate_name'] ?? null) ? $payload['candidate_name'] : null;

    $inputClass = 'mt-1 block w-full rounded-xl border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500';
    $selectClass = $inputClass . ' pr-10';
    $labelClass = 'block text-sm font-medium text-gray-800';
    $sectionClass = 'rounded-2xl border border-gray-200 bg-white/95 p-5 shadow-sm backdrop-blur-sm sm:p-8';
@endphp

@section('content')
<div
    x-data="{
        workingWithTypeId: @js((string) old('working_with_type_id', $fields['working_with_type_id'] ?? '')),
        occupationMasterId: @js((string) old('occupation_master_id', $fields['occupation_master_id'] ?? '')),
        occupations: @js($occupations),
        exemptSlugs: @js($occupationExemptSlugs),
        workingWithTypes: @js($workingWithOptions),
        selectedSlug() {
            const match = this.workingWithTypes.find((row) => String(row.id) === String(this.workingWithTypeId));
            return match ? match.slug : '';
        },
        occupationRequired() {
            return !this.exemptSlugs.includes(this.selectedSlug());
        },
        filteredOccupations() {
            if (!this.workingWithTypeId) {
                return [];
            }
            return this.occupations.filter((row) => String(row.working_with_type_id) === String(this.workingWithTypeId));
        },
        onWorkingWithChange() {
            const stillValid = this.filteredOccupations().some((row) => String(row.id) === String(this.occupationMasterId));
            if (!stillValid) {
                this.occupationMasterId = '';
            }
        }
    }"
    class="mx-auto w-full max-w-5xl"
>
    <div class="{{ $sectionClass }}">
        <div class="flex flex-col gap-4 border-b border-gray-100 pb-5 sm:flex-row sm:items-center">
            @if (!empty($candidatePhoto['available']) && !empty($candidatePhoto['url']))
                <div class="shrink-0 self-start">
                    <img
                        src="{{ $candidatePhoto['url'] }}"
                        alt="{{ $candidateName ?? 'उमेदवार फोटो' }}"
                        class="h-28 w-28 rounded-2xl border-2 border-violet-100 object-cover shadow-sm sm:h-32 sm:w-32"
                        loading="lazy"
                    >
                </div>
            @endif

            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">बायोडाटा नोंदणी पुष्टी</h1>
                @if ($candidateName)
                    <p class="mt-1 text-lg font-semibold text-violet-800">{{ $candidateName }}</p>
                @endif
            </div>
        </div>

        @if (session('success'))
            <div class="mt-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($registrationComplete)
            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                नोंदणी पूर्ण झाली आहे. गरज असल्यास खाली माहिती पुन्हा बदलू शकता.
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-medium">कृपया खालील त्रुटी दुरुस्त करा:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('bulk-intake.register.store', ['token' => $token]) }}" class="mt-6 space-y-8">
            @csrf

            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-violet-700">मूलभूत माहिती</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="{{ $labelClass }}">नाव</label>
                        <input type="text" name="full_name" value="{{ old('full_name', $fields['full_name'] ?? '') }}" required class="{{ $inputClass }} @error('full_name') border-red-400 @enderror">
                        @error('full_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">मोबाईल</label>
                        <input type="tel" name="mobile" value="{{ old('mobile', $fields['mobile'] ?? '') }}" required class="{{ $inputClass }} @error('mobile') border-red-400 @enderror">
                        @error('mobile')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">जन्मतारीख</label>
                        <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $fields['date_of_birth'] ?? '') }}" required class="{{ $inputClass }} @error('date_of_birth') border-red-400 @enderror">
                        @error('date_of_birth')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="md:col-span-2">
                        <x-profile.height-picker
                            :value="old('height_cm', $heightCm)"
                            label="उंची (फूट/इंच)"
                            :required="true"
                        />
                        @error('height_cm')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">लिंग</label>
                        <select name="gender" required class="{{ $selectClass }} @error('gender') border-red-400 @enderror">
                            <option value="">निवडा</option>
                            @foreach ($genders as $gender)
                                <option value="{{ $gender['key'] ?? $gender['id'] }}" @selected((string) old('gender', $fields['gender'] ?? '') === (string) ($gender['key'] ?? $gender['id']))>
                                    {{ $gender['label'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('gender')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">मातृभाषा</label>
                        <select name="mother_tongue_id" required class="{{ $selectClass }} @error('mother_tongue_id') border-red-400 @enderror">
                            <option value="">निवडा</option>
                            @foreach ($motherTongues as $option)
                                <option value="{{ $option['id'] }}" @selected((string) old('mother_tongue_id', $fields['mother_tongue_id'] ?? '') === (string) $option['id'])>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('mother_tongue_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-violet-700">समुदाय व ठिकाण</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="{{ $labelClass }}">वैवाहिक स्थिती</label>
                        <select name="marital_status_id" required class="{{ $selectClass }} @error('marital_status_id') border-red-400 @enderror">
                            <option value="">निवडा</option>
                            @foreach ($maritalStatuses as $option)
                                <option value="{{ $option['id'] }}" @selected((string) old('marital_status_id', $fields['marital_status_id'] ?? '') === (string) $option['id'])>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('marital_status_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">धर्म</label>
                        <select name="religion_id" required class="{{ $selectClass }} @error('religion_id') border-red-400 @enderror">
                            <option value="">निवडा</option>
                            @foreach ($religions as $option)
                                <option value="{{ $option['id'] }}" @selected((string) old('religion_id', $fields['religion_id'] ?? '') === (string) $option['id'])>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('religion_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="md:col-span-2">
                        <label class="{{ $labelClass }}">जात</label>
                        <select name="caste_id" required class="{{ $selectClass }} @error('caste_id') border-red-400 @enderror">
                            <option value="">निवडा</option>
                            @foreach ($castes as $option)
                                <option value="{{ $option['id'] }}" @selected((string) old('caste_id', $fields['caste_id'] ?? '') === (string) $option['id'])>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('caste_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="md:col-span-2">
                        <label class="{{ $labelClass }}">ठिकाण</label>
                        <input type="text" name="location" value="{{ old('location', $fields['location'] ?? '') }}" required class="{{ $inputClass }} @error('location') border-red-400 @enderror">
                        @error('location')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-violet-700">शिक्षण व करिअर</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="{{ $labelClass }}">शिक्षण</label>
                        <input type="text" name="education" value="{{ old('education', $fields['education'] ?? '') }}" required class="{{ $inputClass }} @error('education') border-red-400 @enderror">
                        @error('education')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">कामाचा प्रकार</label>
                        <select
                            name="working_with_type_id"
                            required
                            class="{{ $selectClass }} @error('working_with_type_id') border-red-400 @enderror"
                            x-model="workingWithTypeId"
                            @change="onWorkingWithChange()"
                        >
                            <option value="">निवडा</option>
                            @foreach ($workingWithOptions as $option)
                                <option value="{{ $option['id'] }}" @selected((string) old('working_with_type_id', $fields['working_with_type_id'] ?? '') === (string) $option['id'])>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('working_with_type_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">
                            व्यवसाय
                            <span class="font-normal text-gray-500" x-show="!occupationRequired()">(ऐच्छिक)</span>
                            <span class="font-normal text-red-600" x-show="occupationRequired()">*</span>
                        </label>
                        <select
                            name="occupation_master_id"
                            class="{{ $selectClass }} @error('occupation_master_id') border-red-400 @enderror"
                            x-model="occupationMasterId"
                            :disabled="!workingWithTypeId || !occupationRequired()"
                            :required="occupationRequired()"
                        >
                            <option value="">निवडा</option>
                            <template x-for="row in filteredOccupations()" :key="row.id">
                                <option :value="row.id" x-text="row.label" :selected="String(row.id) === String(occupationMasterId)"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-xs text-gray-500" x-show="workingWithTypeId && occupationRequired() && filteredOccupations().length === 0">
                            या कामाच्या प्रकारासाठी व्यवसाय यादी उपलब्ध नाही. कृपया प्रशासकाशी संपर्क साधा.
                        </p>
                        @error('occupation_master_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">कंपनी <span class="font-normal text-gray-500">(ऐच्छिक)</span></label>
                        <input type="text" name="company_name" value="{{ old('company_name', $fields['company_name'] ?? '') }}" class="{{ $inputClass }}">
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">उत्पन्न <span class="font-normal text-gray-500">(ऐच्छिक)</span></label>
                        <input type="text" name="annual_income" value="{{ old('annual_income', $fields['annual_income'] ?? '') }}" class="{{ $inputClass }}">
                    </div>
                </div>
            </section>

            <div class="border-t border-gray-100 pt-2">
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-violet-700 px-6 py-3 text-base font-semibold text-white shadow-sm transition hover:bg-violet-800 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2 sm:w-auto">
                    नोंदणी माहिती जतन करा
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
