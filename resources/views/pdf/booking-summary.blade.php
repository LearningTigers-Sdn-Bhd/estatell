@extends('pdf.layout')

@php
    /** @var \App\Services\Property\PrintLayout $print */
    /** @var \App\Services\Reservations\BookingSummaryDocument $summary */
    /** @var \App\Models\PrintSetting $settings */
    $money = fn (string $amount): string => number_format((float) $amount, 2);
    $cells = count($summary->strip);
    $credit = $summary->balanceDue !== null && bccomp($summary->balanceDue, '0', 2) < 0;
@endphp

@section('title', __('Booking summary :reference', ['reference' => $summary->reference]))

@section('content')
    @component('pdf.partials.letterhead', ['print' => $print])
        <div class="title">{{ __('Booking summary') }}</div>
        <div>{{ $summary->reference }}</div>
        <div class="small muted">{{ __('As at :date', ['date' => $summary->asAt->format('j M Y, H:i')]) }}</div>
        <div class="small muted">{{ __('Not a tax invoice') }}</div>
        @if ($summary->stamp !== null)
            <div class="heading" style="margin-top: 6pt; color: #b3261e;">{{ $summary->stamp }}</div>
        @endif
    @endcomponent

    @if ($cells > 0)
        <table class="gap-16 info-strip">
            <tr>
                @foreach ($summary->strip as $cell)
                    <td style="width: {{ 100 / $cells }}%;">
                        <div class="small muted">{{ strtoupper($cell['label']) }}</div>
                        <div>{{ $cell['value'] }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @if ($summary->rooms !== [])
        <div class="heading gap-16">{{ __('Rooms') }}</div>
        <table>
            @foreach ($summary->rooms as $line)
                <tr class="rule"><td>{{ $line }}</td></tr>
            @endforeach
        </table>
    @endif

    @if ($summary->showPrices)
        <div class="heading gap-16">{{ __('Charges') }}</div>
        @if ($summary->hasCharges())
            @include('pdf.partials.document-lines', ['groups' => $summary->lines, 'currency' => $summary->currency, 'settings' => $settings, 'words' => false])
        @else
            <div class="small muted">{{ __('No charges') }}</div>
        @endif

        @if ($summary->payments !== [])
            <div class="heading gap-16">{{ __('Payments') }}</div>
            <table>
                @foreach ($summary->payments as $payment)
                    <tr class="rule">
                        <td class="muted nowrap" style="width: 14%;">{{ $payment->date->format('j M') }}</td>
                        <td class="nowrap" style="width: 16%;">{{ $payment->receiptNumber }}</td>
                        <td>{{ $payment->text }}</td>
                        <td class="num">{{ bccomp($payment->amount, '0', 2) < 0 ? '('.$money(ltrim($payment->amount, '-')).')' : $money($payment->amount) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif

        @if ($summary->balanceDue !== null)
        <table class="gap-8">
            <tr class="total">
                <td class="num">{{ $credit ? __('Credit :currency', ['currency' => $summary->currency->getCurrencyCode()]) : __('Balance due :currency', ['currency' => $summary->currency->getCurrencyCode()]) }}</td>
                <td class="num" style="width: 22%;">{{ $money(ltrim($summary->balanceDue, '-')) }}</td>
            </tr>
        </table>
        @endif
        @if ($summary->balanceInWords !== null)
            <div class="small muted gap-8">{{ $summary->balanceInWords }}</div>
        @endif
    @endif

    @if ($summary->cancellationPolicy !== [])
        <div class="heading gap-24">{{ __('Cancellation policy') }}</div>
        @foreach ($summary->cancellationPolicy as $text)
            <div class="small">{!! nl2br(e($text)) !!}</div>
        @endforeach
    @endif

    @if ($summary->paymentDetails !== null)
        <div class="heading gap-16">{{ __('Payment details') }}</div>
        <div class="small">{!! nl2br(e($summary->paymentDetails)) !!}</div>
    @endif
@endsection
