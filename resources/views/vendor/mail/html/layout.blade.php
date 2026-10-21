<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}
.content-cell {
padding: 24px !important;
}
h1 {
font-size: 26px !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
@media (prefers-color-scheme: dark) {
body,
.wrapper,
.body {
background-color: #14160f !important;
color: #e7e8df !important;
}
.header a {
color: #8fd0ae !important;
}
.inner-body {
background-color: #1f211a !important;
border-color: #34372b !important;
border-top-color: #3ecf8e !important;
}
h1,
h2,
strong {
color: #f3f4ef !important;
}
h3,
.eyebrow,
.subcopy p,
.footer p,
.table th {
color: #b9bca9 !important;
}
p,
li,
td {
color: #d6d8c8 !important;
}
a {
color: #5fdba5 !important;
}
.button-primary {
background-color: #3ecf8e !important;
border-color: #3ecf8e !important;
color: #0d1f16 !important;
}
.table th,
.table td,
.subcopy,
.footer {
border-color: #34372b !important;
}
.panel {
border-left-color: #3ecf8e !important;
}
.panel-content {
background-color: #2a2d22 !important;
}
.panel-warning {
border-left-color: #f0a93a !important;
}
.panel-warning .panel-content {
background-color: #3a2c12 !important;
}
.panel-error {
border-left-color: #f0746a !important;
}
.panel-error .panel-content {
background-color: #3d1c19 !important;
}
}

[data-ogsc] body,
[data-ogsb] .wrapper,
[data-ogsb] .body {
background-color: #14160f !important;
color: #e7e8df !important;
}
[data-ogsb] .inner-body {
background-color: #1f211a !important;
}
[data-ogsc] p,
[data-ogsc] li,
[data-ogsc] td,
[data-ogsc] h1 {
color: #d6d8c8 !important;
}
[data-ogsc] .header a {
color: #8fd0ae !important;
}
[data-ogsb] .button-primary {
background-color: #3ecf8e !important;
border-color: #3ecf8e !important;
}
[data-ogsc] .button-primary {
color: #0d1f16 !important;
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
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
