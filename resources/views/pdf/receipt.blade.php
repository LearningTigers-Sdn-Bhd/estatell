@extends('pdf.layout')

@php
    /** @var \App\Services\Payments\ReceiptDocument $receipt */
    $money = fn (string $amount): string => trim($receipt->currency.' '.number_format((float) $amount, 2));
@endphp

@section('title', __('Receipt :number', ['number' => $receipt->receiptNumber]))

@section('content')
    <table>
        <tr>
            <td>
                <div class="heading">{{ $receipt->propertyName }}</div>
                @foreach ($receipt->addressLines as $line)
                    <div class="small">{{ $line }}</div>
                @endforeach
                @foreach ($receipt->contactLines as $line)
                    <div class="small">{{ $line }}</div>
                @endforeach
                @foreach ($receipt->registrationLines as $line)
                    <div class="small muted">{{ $line }}</div>
                @endforeach
            </td>
            <td class="num">
                <div class="title">{{ __('Receipt') }}</div>
                <div>{{ $receipt->receiptNumber }}</div>
                <div class="small muted">{{ $receipt->postingDate }}</div>
            </td>
        </tr>
    </table>

    <table class="gap-24">
        <tr class="rule"><td class="label">{{ __('Received from') }}</td><td>{{ $receipt->payerName }}</td></tr>
        <tr class="rule"><td class="label">{{ __('Reservation') }}</td><td>{{ $receipt->reservationReference }}</td></tr>
        <tr class="rule"><td class="label">{{ __('Folio') }}</td><td>{{ $receipt->folioNumber }}</td></tr>
        <tr class="rule"><td class="label">{{ __('Method') }}</td><td>{{ $receipt->method }}</td></tr>
        @if ($receipt->reference !== null)
            <tr class="rule"><td class="label">{{ __('Reference') }}</td><td>{{ $receipt->reference }}</td></tr>
        @endif
        <tr class="rule"><td class="label">{{ __('Purpose') }}</td><td>{{ $receipt->purpose }}</td></tr>
        <tr class="rule"><td class="label">{{ __('Received') }}</td><td>{{ $receipt->receivedAt }}</td></tr>
    </table>

    <table class="gap-24">
        <tr class="rule"><td>{{ __('Amount paid') }}</td><td class="num">{{ $money($receipt->amountPaid) }}</td></tr>
        @if ($receipt->surcharge !== null)
            <tr class="rule"><td>{{ __('Surcharge') }}</td><td class="num">{{ $money($receipt->surcharge) }}</td></tr>
            <tr class="rule"><td>{{ __('Tax on surcharge') }}</td><td class="num">{{ $money($receipt->surchargeTax ?? '0') }}</td></tr>
        @endif
        <tr class="total"><td>{{ __('Total collected') }}</td><td class="num">{{ $money($receipt->total) }}</td></tr>
    </table>

    <p class="gap-24 small muted">{{ __('Received by :name', ['name' => $receipt->receivedBy]) }}</p>
@endsection
