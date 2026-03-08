{{-- Phase-5 SSOT: Full edit — all sections in one form (used when section=full, e.g. from Edit Profile / matrimony.profile.edit). Point 4.2: Marriage/children canonical in Marriages section. --}}
@include('matrimony.profile.wizard.sections.basic_info')
@include('matrimony.profile.wizard.sections.physical')
@include('matrimony.profile.wizard.sections.marriages')
@include('matrimony.profile.wizard.sections.personal_family')

@include('matrimony.profile.wizard.sections.siblings')
@include('matrimony.profile.wizard.sections.relatives')
@include('matrimony.profile.wizard.sections.alliance')
{{-- Location: address captured in Extended Family (Native Place, Maternal Ajol) and relative rows; no duplicate section in full form. --}}
@include('matrimony.profile.wizard.sections.property')
@include('matrimony.profile.wizard.sections.horoscope')
@include('matrimony.profile.wizard.sections.contacts')
@include('matrimony.profile.wizard.sections.about_preferences')
@include('matrimony.profile.wizard.sections.photo')
