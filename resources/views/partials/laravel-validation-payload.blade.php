@if ($errors->any())
    <script type="application/json" id="laravel-validation-errors">@json(['keys' => $errors->keys(), 'messages' => $errors->getMessages()])</script>
@endif
