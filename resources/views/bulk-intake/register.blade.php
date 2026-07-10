@extends('layouts.bulk-register')

@php
    $profile = $payload['profile'];
    $genders = $payload['genders'] ?? collect();
    $motherTongues = is_array($payload['mother_tongues'] ?? null) ? $payload['mother_tongues'] : [];
    $maritalStatuses = $payload['marital_statuses'] ?? collect();
    $profileMarriages = $payload['profile_marriages'] ?? collect();
    $profileChildren = $payload['profile_children'] ?? collect();
    $childLivingWithOptions = $payload['child_living_with_options'] ?? collect();
    $currencies = $payload['currencies'] ?? collect();
    $residenceHints = is_array($payload['residence_hints'] ?? null) ? $payload['residence_hints'] : [];
    $residenceDisplay = is_string($payload['residence_display'] ?? null) ? $payload['residence_display'] : '';
    $mobile = is_string($payload['mobile'] ?? null) ? $payload['mobile'] : '';
    $motherTongueId = $payload['mother_tongue_id'] ?? $profile->mother_tongue_id ?? null;
    $candidateName = is_string($payload['candidate_name'] ?? null) ? $payload['candidate_name'] : null;

    $dobValue = old('date_of_birth');
    if (! is_string($dobValue) || $dobValue === '') {
        $dobRaw = $profile->date_of_birth ?? null;
        if ($dobRaw instanceof \DateTimeInterface) {
            $dobValue = $dobRaw->format('Y-m-d');
        } elseif (is_string($dobRaw) && trim($dobRaw) !== '') {
            try {
                $dobValue = \Carbon\Carbon::parse($dobRaw)->format('Y-m-d');
            } catch (\Throwable $e) {
                $dobValue = $dobRaw;
            }
        } else {
            $dobValue = '';
        }
    }

    $inputClass = 'mt-1 block w-full rounded-xl border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-violet-500 focus:ring-violet-500';
    $selectClass = $inputClass . ' pr-10';
    $labelClass = 'block text-sm font-medium text-gray-800';
    $sectionClass = 'rounded-2xl border border-gray-200 bg-white/95 p-5 shadow-sm backdrop-blur-sm sm:p-8';
@endphp

@section('content')
<div class="mx-auto w-full max-w-5xl">
    <div class="{{ $sectionClass }}">
        <div class="border-b border-gray-100 pb-5">
            <h1 class="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">बायोडाटा नोंदणी पुष्टी</h1>
            @if ($candidateName)
                <p class="mt-1 text-lg font-semibold text-violet-800">{{ $candidateName }}</p>
            @endif
        </div>

        @if (session('success'))
            <div class="mt-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
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

        <form method="POST" action="{{ route('bulk-intake.register.store', ['token' => $token]) }}" id="bulk-registration-form" class="mt-6 space-y-8">
            @csrf

            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-violet-700">मूलभूत माहिती</h2>
                <div class="mt-4 space-y-4">
                    <div>
                        <label class="{{ $labelClass }}">नाव</label>
                        <input type="text" name="full_name" value="{{ old('full_name', $profile->full_name) }}" required class="{{ $inputClass }} @error('full_name') border-red-400 @enderror">
                        @error('full_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="{{ $labelClass }}">मोबाईल</label>
                            <input type="tel" name="mobile" value="{{ old('mobile', $mobile) }}" required class="{{ $inputClass }} @error('mobile') border-red-400 @enderror">
                            @error('mobile')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="{{ $labelClass }}">जन्मतारीख</label>
                            <input type="date" name="date_of_birth" value="{{ $dobValue }}" required class="{{ $inputClass }} @error('date_of_birth') border-red-400 @enderror">
                            @error('date_of_birth')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div
                        x-data="{ genderId: {{ (int) old('gender_id', $profile->gender_id) ?: 'null' }} }"
                    >
                        <label class="{{ $labelClass }}">लिंग <span class="text-red-600">*</span></label>
                        <input type="hidden" name="gender_id" :value="genderId">
                        <div class="mt-2 flex overflow-hidden rounded-xl border border-gray-300 bg-gray-50">
                            @foreach ($genders as $gender)
                                @php
                                    $selectedBg = $gender->key === 'male' ? 'bg-blue-600' : 'bg-pink-500';
                                @endphp
                                <button
                                    type="button"
                                    class="flex-1 px-4 py-3 text-sm font-semibold text-gray-600 transition"
                                    :class="genderId == {{ $gender->id }} ? '{{ $selectedBg }} text-white' : 'hover:bg-white'"
                                    @click="genderId = {{ $gender->id }}"
                                >
                                    {{ $gender->key === 'male' ? 'पुरुष' : 'स्त्री' }}
                                </button>
                            @endforeach
                        </div>
                        @error('gender_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">मातृभाषा</label>
                        <select name="mother_tongue_id" required class="{{ $selectClass }} @error('mother_tongue_id') border-red-400 @enderror">
                            <option value="">निवडा</option>
                            @foreach ($motherTongues as $option)
                                <option value="{{ $option['id'] }}" @selected((string) old('mother_tongue_id', $motherTongueId) === (string) $option['id'])>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('mother_tongue_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <x-profile.height-picker
                            :value="old('height_cm', $profile->height_cm)"
                            label="उंची (फूट/इंच)"
                            :required="true"
                        />
                        @error('height_cm')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-violet-700">वैवाहिक स्थिती</h2>
                <div class="mt-4">
                    @include('matrimony.profile.wizard.sections.marital_engine', [
                        'namePrefix' => '',
                        'profile' => $profile,
                        'maritalStatuses' => $maritalStatuses,
                        'profileMarriages' => $profileMarriages,
                        'profileChildren' => $profileChildren,
                        'childLivingWithOptions' => $childLivingWithOptions,
                        'hideStatusDetailsOptional' => true,
                    ])
                </div>
            </section>

            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-violet-700">समुदाय व ठिकाण</h2>
                <div class="mt-4 space-y-4">
                    <x-profile.religion-caste-selector :profile="$profile" :show-subcaste="false" />

                    <div>
                        <label class="{{ $labelClass }}">ठिकाण</label>
                        <x-profile.location-typeahead
                            context="residence"
                            mode="simple"
                            :noBorder="true"
                            :value="old('location_input', $residenceDisplay)"
                            placeholder="शहर किंवा गाव शोधा"
                            :dataLocationId="$residenceHints['location_id'] ?? ''"
                            :dataCountryId="$residenceHints['country_id'] ?? ''"
                            :dataStateId="$residenceHints['state_id'] ?? ''"
                            :dataDistrictId="$residenceHints['district_id'] ?? ''"
                            :dataTalukaId="$residenceHints['taluka_id'] ?? ''"
                            label=""
                        />
                        @error('location_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        @error('location_input')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-violet-700">शिक्षण व करिअर</h2>
                <div class="mt-4 space-y-5">
                    <x-education-multiselect-engine
                        :profile="$profile"
                        form-selector="#bulk-registration-form"
                        suffix="bulk-registration-education"
                    />

                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <x-occupation-search-engine
                            :profile="$profile"
                            form-selector="#bulk-registration-form"
                        />
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">कंपनी <span class="font-normal text-gray-500">(ऐच्छिक)</span></label>
                        <input type="text" name="company_name" value="{{ old('company_name', $profile->company_name) }}" class="{{ $inputClass }}">
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <x-income-engine
                            label="उत्पन्न"
                            name-prefix="income"
                            empty-value-type-default="undisclosed"
                            :profile="$profile"
                            :currencies="$currencies"
                        />
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.LocationTypeahead && window.LocationTypeahead.init) {
        window.LocationTypeahead.init();
    }
});
</script>
@endsection
