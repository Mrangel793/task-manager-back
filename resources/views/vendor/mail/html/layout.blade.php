<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 620px) {
.inner-body {
width: 100% !important;
border-radius: 0 !important;
margin-top: 0 !important;
}

.content-cell {
padding: 24px !important;
}

.footer {
width: 100% !important;
}

.header {
padding: 20px 0 35px !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #f0f4f8;">

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #f0f4f8; margin: 0; padding: 0;">
<tr>
<td align="center" style="padding: 20px 0;">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 600px;">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: none;">
<table class="inner-body" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07), 0 1px 3px rgba(0, 0, 0, 0.05); margin: -30px auto 0; border: 1px solid #e2e8f0;">
<!-- Body content -->
<tr>
<td class="content-cell" style="padding: 40px;">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
