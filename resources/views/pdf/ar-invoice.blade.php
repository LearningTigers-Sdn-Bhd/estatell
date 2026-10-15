@extends('pdf.layout')

@php
    /** @var \App\Services\CityLedger\ArInvoiceDocument $document */
    /** @var \App\Models\PrintSetting $settings */
    $code = $document->currencyCode();
    $money = fn (string $amount): string => trim($code.' '.number_format((float) $amount, 2));
    $plain = fn (string $amount): string => number_format((float) $amount, 2);
    $cells = count($document->strip);
@endphp

@section('title', $document->title.' '.$document->number)

@section('content')
    @component('pdf.partials.letterhead', ['print' => $print])
        <div class="title">{{ $document->title }}</div>
        <div>{{ $document->number }}</div>
        @if ($document->isVoid)
            <div class="heading">{{ __('VOID') }}</div>
        @endif
    @endcomponent

    @if ($document->isCreditNote)
        <table class="gap-24">
            <tr>
                <td>
                    <div class="small muted">{{ __('Bill to') }}</div>
                    <div class="heading">{{ $document->companyName }}</div>
                    @foreach ($document->billToLines as $line)
                        <div class="small">{{ $line }}</div>
                    @endforeach
                </td>
                <td class="num">
                    <div class="small muted">{{ __('Issued') }}</div>
                    <div>{{ $document->issuedOn }}</div>
                    @if ($document->dueOn !== null)
                        <div class="small muted gap-8">{{ __('Due') }}</div>
                        <div>{{ $document->dueOn }}</div>
                    @endif
                    @if ($document->creditFor !== null)
                        <div class="small muted gap-8">{{ __('Corrects invoice') }}</div>
                        <div>{{ $document->creditFor }}</div>
                    @endif
                </td>
            </tr>
        </table>

        @if ($document->reason !== null)
            <p class="gap-24"><span class="muted">{{ __('Reason') }}:</span> {{ $document->reason }}</p>
        @endif

        <table class="gap-24">
            <tr class="total"><td>{{ __('Total') }}</td><td class="num">{{ $money($document->total) }}</td></tr>
        </table>
    @else
        @if ($cells > 0)
            <table class="gap-16 info-strip">
                <tr>
                    @foreach ($document->strip as $cell)
                        <td style="width: {{ 100 / $cells }}%;">
                            <div class="small muted">{{ strtoupper($cell['label']) }}</div>
                            @foreach ($cell['lines'] as $line)
                                <div @if (! $loop->first) class="small" @endif>{{ $line }}</div>
                            @endforeach
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif

        @include('pdf.partials.rooms-block', ['rooms' => $document->rooms])

        @if ($document->lines !== null)
            @include('pdf.partials.document-lines', ['groups' => $document->lines, 'currency' => $document->currency, 'settings' => $settings, 'words' => false])
        @endif

        @if ($document->payments !== [])
            <div class="heading gap-16">{{ __('Paid at the hotel') }}</div>
            <table>
                @foreach ($document->payments as $payment)
                    <tr class="rule">
                        <td class="muted nowrap" style="width: 14%;">{{ $payment->date->format('j M') }}</td>
                        <td class="nowrap" style="width: 16%;">{{ $payment->receiptNumber }}</td>
                        <td>{{ $payment->text }}</td>
                        <td class="num">{{ bccomp($payment->amount, '0', 2) < 0 ? '('.$plain(ltrim($payment->amount, '-')).')' : $plain($payment->amount) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        <table class="gap-8">
            <tr class="total">
                <td class="num">{{ __('Amount due :currency', ['currency' => $code]) }}</td>
                <td class="num" style="width: 22%;">{{ $plain($document->amountDue) }}</td>
            </tr>
        </table>

        @if ($document->amountInWords !== null)
            <div class="small muted gap-8">{{ $document->amountInWords }}</div>
        @endif

        @if ($document->paymentDetails !== null)
            <div class="small gap-8"><span class="muted">{{ __('Payment details') }}:</span> {{ $document->paymentDetails }}</div>
        @endif
    @endif
@endsection
