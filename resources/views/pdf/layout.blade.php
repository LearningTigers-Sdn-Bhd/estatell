{{--
    The shared PDF layout. dompdf has no flex or grid, so every layout is a table. Tokens: DejaVu Sans, spacing in
    steps of 4pt, rules of 0.5pt (light) and 1pt (strong). See docs/design-system/11-accessibility-performance-pdfs.md.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 32pt 40pt; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.4; color: #1a1a1a; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4pt 0; vertical-align: top; text-align: left; }
        th { font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #6b6b6b; }
        .small { font-size: 8pt; }
        .title { font-size: 16pt; font-weight: bold; }
        .heading { font-size: 11pt; font-weight: bold; }
        .label { width: 32%; color: #6b6b6b; }
        .gap-8 { margin-top: 8pt; }
        .gap-16 { margin-top: 16pt; }
        .gap-24 { margin-top: 24pt; }
        .rule td, .rule th { border-bottom: 0.5pt solid #c8c8c8; }
        .total td { border-top: 1pt solid #1a1a1a; font-weight: bold; padding-top: 8pt; }
    </style>
    @stack('head')
</head>
<body>
    @yield('content')
</body>
</html>
