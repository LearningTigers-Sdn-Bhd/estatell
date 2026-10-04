@extends('pdf.layout')

@php
    /** @var \App\Services\CityLedger\ArReceiptDocument $receipt */
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
                <div class="small muted">{{ $receipt->receivedOn }}</div>
                @if ($receipt->isVoid)
                    <div class="heading">{{ __('VOID') }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="gap-24">
        <tr class="rule"><td class="label">{{ __('Received from') }}</td><td>{{ $receipt->companyName }}</td></tr>
        <tr class="rule"><td class="label">{{ __('Received against') }}</td><td>{{ __('City ledger') }}</td></tr>
        <tr class="rule"><td class="label">{{ __('Method') }}</td><td>{{ $receipt->method }}</td></tr>
        @if ($receipt->reference !== null)
            <tr class="rule"><td class="label">{{ __('Reference') }}</td><td>{{ $receipt->reference }}</td></tr>
        @endif
        <tr class="rule"><td class="label">{{ __('Received on') }}</td><td>{{ $receipt->receivedOn }}</td></tr>
        @if ($receipt->notes !== null)
            <tr class="rule"><td class="label">{{ __('Notes') }}</td><td>{{ $receipt->notes }}</td></tr>
        @endif
    </table>

    <table class="gap-24">
        <tr class="total"><td>{{ __('Total received') }}</td><td class="num">{{ $money($receipt->total) }}</td></tr>
    </table>

    <p class="gap-24 small muted">{{ __('Received by :name', ['name' => $receipt->receivedBy]) }}</p>
@endsection
