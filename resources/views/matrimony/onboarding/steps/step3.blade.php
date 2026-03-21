<form method="POST" action="{{ route('matrimony.onboarding.store', ['step' => 3]) }}" class="space-y-6">
    @csrf
    <x-profile.religion-caste-selector :profile="$profile" namePrefix="" />

    <x-onboarding.form-footer
        :back-url="route('matrimony.onboarding.show', ['step' => 2])"
    />
</form>
