<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
<p style="margin: 0; color: #64748b; font-size: 13px;">
&copy; {{ date('Y') }} {{ config('app.name') }}. Todos los derechos reservados.
</p>
<p style="margin: 8px 0 0; color: #94a3b8; font-size: 12px;">
Este correo fue enviado automaticamente. Por favor no responda a este mensaje.
</p>
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
