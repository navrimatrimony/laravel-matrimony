<x-mail::message>
# {{ $title }}

{{ $intro }}

@if(filled($detail ?? null))
<x-mail::panel>
{{ $detail }}
</x-mail::panel>
@endif

<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

@if(filled($secondaryUrl ?? null) && filled($secondaryText ?? null))
<x-mail::button :url="$secondaryUrl" color="success">
{{ $secondaryText }}
</x-mail::button>
@endif

{{ __('mail.common.salutation') }}<br>
{{ config('app.name') }}
</x-mail::message>
