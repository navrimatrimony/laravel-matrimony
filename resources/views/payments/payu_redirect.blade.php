<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Redirecting to payment…</title>
</head>
<body style="font-family: system-ui, sans-serif; margin: 2rem; text-align: center;">
    <p>Redirecting to PayU. Please wait…</p>
    <form id="payu_checkout" method="post" action="{{ $action }}">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <noscript>
            <button type="submit">Continue to payment</button>
        </noscript>
    </form>
    <script>
        document.getElementById('payu_checkout').submit();
    </script>
</body>
</html>
