<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center']) }} style="background-color: #4f46e5; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; display: inline-flex; align-items: center;">
    {{ $slot }}
</button>
