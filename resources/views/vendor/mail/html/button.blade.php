@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 30px auto; text-align: {{ $align }};">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
@php
$buttonStyles = match($color) {
    'primary', 'blue' => 'background-color: #2563eb; border-color: #2563eb;',
    'success', 'green' => 'background-color: #059669; border-color: #059669;',
    'error', 'red' => 'background-color: #dc2626; border-color: #dc2626;',
    default => 'background-color: #2563eb; border-color: #2563eb;',
};
@endphp
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" style="display: inline-block; {{ $buttonStyles }} color: #ffffff !important; font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; font-size: 14px; font-weight: 600; line-height: 1; text-decoration: none; padding: 14px 28px; border-radius: 8px; text-align: center;">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
