{{--
    The shared PDF layout. dompdf has no flex or grid, so every layout is a table. Tokens: spacing in steps of 4pt,
    rules of 0.5pt (light) and 1pt (strong). See docs/design-system/11-accessibility-performance-pdfs.md.

    A view rendered through PrintedPdf gets `$print` (App\Services\Property\PrintLayout): the property's font style,
    font size and footer. Each font-family list ends with Noto Sans SC and Noto Sans TC, so Chinese text prints in every
    style. Views without it (the registration card, the sign-in slips) keep DejaVu Sans at 9pt and no footer.
--}}
@php
    /** @var \App\Services\Property\PrintLayout|null $print */
    $print ??= null;
    $body = $print?->fontSize->points() ?? 9.0;
    $bodyFamily = $print !== null ? \App\Enums\PdfFont::stack($print->font->bodyFamily()) : "'DejaVu Sans', sans-serif";
    // Without a print style the headings inherit the body font, which the registration card sets itself.
    $headingFamily = $print !== null ? 'font-family: '.\App\Enums\PdfFont::stack($print->font->headingFamily()).';' : '';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @foreach ($print?->font->fontFaces() ?? [] as $face)
            @font-face { font-family: '{{ $face['family'] }}'; font-style: normal; font-weight: {{ $face['weight'] }}; src: url('{{ $face['file'] }}') format('truetype'); }
        @endforeach
        @page { margin: 32pt 40pt {{ $print?->footerText !== null ? 48 : 32 }}pt 40pt; }
        body { font-family: {!! $bodyFamily !!}; font-size: {{ $body }}pt; line-height: 1.4; color: #1a1a1a; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4pt 0; vertical-align: top; text-align: left; }
        th { font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #6b6b6b; }
        .small { font-size: {{ $body - 1 }}pt; }
        .title { {!! $headingFamily !!} font-size: {{ $body + 7 }}pt; font-weight: bold; }
        .heading { {!! $headingFamily !!} font-size: {{ $body + 2 }}pt; font-weight: bold; }
        .label { width: 32%; color: #6b6b6b; }
        .gap-8 { margin-top: 8pt; }
        .gap-16 { margin-top: 16pt; }
        .gap-24 { margin-top: 24pt; }
        .rule td, .rule th { border-bottom: 0.5pt solid #c8c8c8; }
        .total td { border-top: 1pt solid #1a1a1a; font-weight: bold; padding-top: 8pt; }
        .letterhead .logo { width: 72pt; padding-right: 12pt; }
        .letterhead .logo img { max-width: 72pt; max-height: 48pt; }
        .page-footer { position: fixed; left: 0; right: 0; bottom: -32pt; text-align: center; font-size: {{ $body - 1 }}pt; color: #6b6b6b; }
    </style>
    @stack('head')
</head>
<body>
    @if ($print?->footerText !== null)
        <div class="page-footer">{{ $print->footerText }}</div>
    @endif
    @yield('content')
</body>
</html>
