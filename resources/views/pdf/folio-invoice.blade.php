@extends('pdf.layout')

@php
    /** @var \App\Services\Folio\FolioInvoiceDocument $invoice */
    /** @var \App\Models\PrintSetting $settings */
    $money = fn (string $amount): string => number_format((float) $amount, 2);
    $cells = count($invoice->strip);
@endphp

@section('title', __('Invoice :number', ['number' => $invoice->invoiceNumber]))

@section('content')
    @component('pdf.partials.letterhead', ['print' => $print])
        <div class="title">{{ __('Invoice') }}</div>
        <div>{{ $invoice->invoiceNumber }}</div>
        <div class="small muted">{{ $invoice->closedOn->format('j M Y') }}</div>
    @endcomponent

    @if ($cells > 0)
        <table class="gap-16">
            <tr>
                @foreach ($invoice->strip as $cell)
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

    @include('pdf.partials.rooms-block', ['rooms' => $invoice->rooms])

    @if ($invoice->hasCharges())
        @include('pdf.partials.document-lines', ['groups' => $invoice->lines, 'currency' => $invoice->currency, 'settings' => $settings, 'words' => false])
    @else
        <div class="small muted gap-16">{{ __('No charges') }}</div>
    @endif

    @if ($invoice->payments !== [])
        <div class="heading gap-16">{{ __('Payments') }}</div>
        <table>
            @foreach ($invoice->payments as $payment)
                <tr class="rule">
                    <td class="muted" style="width: 14%;">{{ $payment->date->format('j M') }}</td>
                    <td style="width: 16%;">{{ $payment->receiptNumber }}</td>
                    <td>{{ $payment->text }}</td>
                    <td class="num">{{ bccomp($payment->amount, '0', 2) < 0 ? '('.$money(ltrim($payment->amount, '-')).')' : $money($payment->amount) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <table class="gap-8">
        <tr class="total">
            <td class="num">{{ __('Balance :currency', ['currency' => $invoice->currency->getCurrencyCode()]) }}</td>
            <td class="num" style="width: 22%;">{{ $money($invoice->balance) }}</td>
        </tr>
    </table>

    @if ($invoice->totalInWords !== null)
        <div class="small muted gap-8">{{ $invoice->totalInWords }}</div>
    @endif

    @if ($invoice->showSignatureLines)
        <table class="gap-24">
            <tr>
                <td style="width: 50%; padding-top: 32pt;">{{ __('Guest') }} ____________________</td>
                <td style="width: 50%; padding-top: 32pt;">{{ __('Cashier') }} ____________________</td>
            </tr>
        </table>
    @endif
@endsection
