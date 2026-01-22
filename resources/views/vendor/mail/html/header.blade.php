@props(['url'])
<tr>
<td class="header" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); padding: 30px 0 50px; text-align: center;">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<table cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;">
<tr>
<td style="vertical-align: middle; padding-right: 12px;">
<div style="width: 45px; height: 45px; background-color: rgba(255,255,255,0.2); border-radius: 10px; display: inline-block; text-align: center; line-height: 45px;">
<span style="font-size: 24px;">&#10003;</span>
</div>
</td>
<td style="vertical-align: middle;">
<span style="color: #ffffff; font-size: 24px; font-weight: 700; letter-spacing: -0.5px;">{{ $slot }}</span>
</td>
</tr>
</table>
</a>
</td>
</tr>
