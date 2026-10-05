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
        .section { border-top: 0.5pt solid #c8c8c8; margin-top: 12pt; padding-top: 8pt; }
        .section-title { font-size: 7pt; letter-spacing: 0.5pt; text-transform: uppercase; color: #6b6b6b; margin-bottom: 4pt; }
        .grid td { width: 50%; padding: 0 12pt 8pt 0; }
        .grid .value { font-weight: bold; min-height: 11pt; }
        .box { font-family: 'Noto Sans Symbols 2', sans-serif; font-size: 12pt; width: 20pt; }
        .items td { vertical-align: middle; }
        .terms p { margin: 0 0 6pt 0; }
        .terms h1, .terms h2, .terms h3 { font-size: 10pt; margin: 8pt 0 4pt 0; }
        .signature { page-break-inside: avoid; }
        .signature img { max-height: 64pt; max-width: 220pt; }
        .sign-line { border-bottom: 0.5pt solid #1a1a1a; height: 40pt; }
        .sign-line img { margin-top: 0; }
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
                                    <div class="small muted">{{ $field['label'] }}</div>
                                    <div class="value">{{ $field['value'] !== '' ? $field['value'] : "\u{00a0}" }}</div>
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

    @if ($document->marketing !== null)
        <div class="section">
            <table class="items"><tr>
                <td class="box">{{ $box($document->marketing) }}</td>
                <td>{{ __('I agree to receive offers from :name.', ['name' => $document->propertyName]) }}</td>
            </tr></table>
        </div>
    @endif

    <div class="section">
        <div class="section-title">{{ __('Terms') }}</div>
        <div class="terms">{!! $document->termsHtml !!}</div>
        <table class="items gap-8">
            @foreach ($document->items as $item)
                <tr><td class="box">{{ $box($item['ticked']) }}</td><td>{{ $item['text'] }}</td></tr>
            @endforeach
        </table>
    </div>

    <div class="signature gap-24">
        <div class="sign-line">
            @if ($document->signatureDataUri)
                <img src="{{ $document->signatureDataUri }}" alt="">
            @endif
        </div>
        <div class="small muted">{{ __('Guest signature') }}
            @if ($document->signatureDataUri)
                · {{ $document->signerName }} · {{ $document->signedAt }}
            @endif
        </div>
    </div>
@endsection
