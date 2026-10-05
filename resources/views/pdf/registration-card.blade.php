@extends('pdf.layout')

@php
    /** @var \App\Services\Registration\RegistrationCardDocument $document */
    $body = $document->needsCjk() ? "'Noto Sans SC'" : "'DejaVu Sans'";
    $sections = [
        'guest' => __('Guest details'),
        'stay' => __('Stay details'),
        'prices' => __('Estimated charges at booking'),
    ];
    $box = fn (bool $ticked): string => $ticked ? "\u{2611}" : "\u{2610}";
@endphp

@section('title', __('Registration card'))

@push('head')
    <style>
        @font-face { font-family: 'Noto Sans SC'; font-style: normal; font-weight: normal; src: url('{{ resource_path('fonts/NotoSansSC-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Noto Sans Symbols 2'; font-style: normal; font-weight: normal; src: url('{{ resource_path('fonts/NotoSansSymbols2-Regular.ttf') }}') format('truetype'); }
        @page { margin: 32pt 40pt 64pt 40pt; }
        body { font-family: {!! $body !!}, sans-serif; }
        .footer { position: fixed; bottom: -40pt; left: 0; right: 0; }
        .section { border-top: 0.5pt solid #c8c8c8; margin-top: 8pt; padding-top: 6pt; }
        .section-title { font-size: 7pt; letter-spacing: 0.5pt; text-transform: uppercase; color: #6b6b6b; margin-bottom: 4pt; }
        .grid td { width: 50%; padding: 0 12pt 4pt 0; }
        .grid .label-text { font-size: 7.5pt; color: #6b6b6b; }
        .grid .value { font-size: 9pt; font-weight: bold; min-height: 11pt; }
        .box { font-family: 'Noto Sans Symbols 2', sans-serif; font-size: 12pt; width: 20pt; }
        .items td { vertical-align: middle; padding: 1pt 0; }
        .terms { font-size: 7.5pt; line-height: 1.5; }
        .terms p { margin: 0 0 6pt 0; }
        .terms h1, .terms h2, .terms h3, .terms h4 { font-size: 8.5pt; font-weight: bold; margin: 8pt 0 3pt 0; }
        .terms ul, .terms ol { margin: 0 0 6pt 0; padding-left: 12pt; }
        .terms li { margin: 0 0 2pt 0; padding: 0; }
        .checks { font-size: 8pt; }
        .checks td { padding: 0; }
        .checks .box { font-size: 8pt; width: 14pt; line-height: 0.6; height: 11pt; }
        .signature { page-break-inside: avoid; }
        .signature td { width: 33.33%; padding: 0 12pt 0 0; }
        .sign-space { height: 40pt; }
        .sign-space img { max-height: 40pt; max-width: 100%; }
        .sign-line { border-top: 0.75pt solid #1a1a1a; }
        .sign-label { font-size: 7.5pt; color: #6b6b6b; margin-top: 2pt; }
        .sign-meta { font-size: 7.5pt; margin-top: 2pt; }
    </style>
@endpush

@section('content')
    <div class="footer small muted">{{ $document->propertyName }}@if ($document->number) · {{ $document->number }}@endif</div>

    <table>
        <tr>
            <td>
                <div class="heading">{{ $document->propertyName }}</div>
                <div class="small muted">{{ __('Registration card') }}</div>
            </td>
            <td class="num">
                @if ($document->number)
                    <div>{{ $document->number }}</div>
                @endif
            </td>
        </tr>
    </table>

    @foreach ($sections as $key => $title)
        @php($rows = array_values(array_filter($document->fields, fn (array $field): bool => $field['section'] === $key)))
        @if ($rows !== [])
            <div class="section">
                <div class="section-title">{{ $title }}</div>
                <table class="grid">
                    @foreach (array_chunk($rows, 2) as $pair)
                        <tr>
                            @foreach ($pair as $field)
                                <td>
                                    <div class="label-text">{{ $field['label'] }}</div>
                                    @if ($field['value'] !== '')
                                        <div class="value">{{ $field['value'] }}</div>
                                    @else
                                        <div class="value muted">{{ __('Not set') }}</div>
                                    @endif
                                </td>
                            @endforeach
                            @if (count($pair) === 1)
                                <td></td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    @endforeach

    <div class="section">
        <div class="section-title">{{ __('Terms') }}</div>
        <div class="terms">{!! $document->termsHtml !!}</div>
        <table class="items checks gap-8">
            @foreach ($document->items as $item)
                <tr><td class="box">{{ $box($item['ticked']) }}</td><td>{{ $item['text'] }}</td></tr>
            @endforeach
            @if ($document->marketing !== null)
                <tr><td class="box">{{ $box($document->marketing) }}</td><td>{{ __('I agree to receive offers from :name.', ['name' => $document->propertyName]) }}</td></tr>
            @endif
        </table>
    </div>

    <table class="signature gap-16">
        <tr>
            <td class="sign-cell">
                <div class="sign-space">
                    @if ($document->signatureDataUri)
                        <img src="{{ $document->signatureDataUri }}" alt="">
                    @endif
                </div>
                <div class="sign-line"></div>
                <div class="sign-label">{{ __('Guest signature') }}</div>
                <div class="sign-meta">{{ __('Name') }}: {{ $document->signerName }} &nbsp; {{ __('Date') }}: {{ $document->signedAt }}</div>
            </td>
            <td></td>
            <td></td>
        </tr>
    </table>
@endsection
