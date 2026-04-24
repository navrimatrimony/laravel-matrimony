<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('revenue_summary.payu_title') }}</title>
</head>
<body class="bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <div class="mx-auto max-w-lg px-4 py-10 text-center">
        <p class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ __('revenue_summary.payu_redirect_note') }}</p>
        @if (! empty($revenueSummary) && is_array($revenueSummary))
            @include('partials.payu-checkout-summary', ['revenueSummary' => $revenueSummary])
        @endif
        <form id="payu_checkout" method="post" action="{{ $action }}">
            @foreach ($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <noscript>
                <button type="submit" class="mt-6 rounded-xl bg-indigo-600 px-6 py-3 text-sm font-bold text-white">{{ __('Continue to payment') }}</button>
            </noscript>
        </form>
    </div>
    <script>
        (function () {
            var delayMs = 2200;
            setTimeout(function () {
                var f = document.getElementById('payu_checkout');
                if (f) f.submit();
            }, delayMs);
        })();
    </script>
</body>
</html>
