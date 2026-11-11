@extends('pdf.layout')

@php
    /** @var \App\Data\Commissions\PayoutVoucherData $voucher */
    $row = $voucher->voucher;
    $currency = $row->currency?->code ?? '';
    $money = fn (string $amount): string => trim($currency.' '.number_format((float) $amount, 2));
    $date = fn (?string $value): string => $value === null ? '—' : \Carbon\CarbonImmutable::parse($value)->format('j M Y');
    $account = $voucher->bankAccount;
    $masked = $account === null ? null : '•••• '.substr($account['accountNumber'], -4);
@endphp

@section('title', __('Payout voucher :number', ['number' => $row->number]))

@section('content')
    <div class="title">{{ __('Payout voucher') }}</div>
    <div>{{ $row->number }} · {{ $row->status->label }}</div>
    <div class="small muted">{{ __('Issued by Estatell to :property', ['property' => $row->propertyName]) }}</div>

    <table class="gap-24">
        <tr class="rule"><td class="label">{{ __('Property') }}</td><td>{{ $row->propertyName }}</td></tr>
        <tr class="rule"><td class="label">{{ __('Cycle') }}</td><td>{{ $date($row->periodStart) }} – {{ $date($row->periodEnd) }}</td></tr>
        <tr class="rule"><td class="label">{{ __('Due on') }}</td><td>{{ $date($row->dueOn) }}</td></tr>
        @if ($account !== null)
            <tr class="rule"><td class="label">{{ __('Bank account') }}</td><td>{{ $account['bankName'] }} · {{ $account['accountName'] }} · {{ $masked }}</td></tr>
        @endif
        @if ($voucher->paidOn !== null)
            <tr class="rule"><td class="label">{{ __('Paid on') }}</td><td>{{ $date($voucher->paidOn) }} · {{ $voucher->paidReference }}</td></tr>
        @endif
    </table>

    <table class="gap-24">
        <tr class="rule">
            <td class="label">{{ __('Due date') }}</td>
            <td class="label">{{ __('Booking') }}</td>
            <td class="label">{{ __('Type') }}</td>
            <td class="label num">{{ __('Amount') }}</td>
        </tr>
        @foreach ($voucher->lines as $line)
            <tr class="rule">
                <td>{{ $date($line->dueDate) }}</td>
                <td>{{ $line->bookingReference ?? '—' }}</td>
                <td>{{ $line->label }}@if ($line->reason !== null)<div class="small muted">{{ $line->reason }}</div>@endif</td>
                <td class="num">{{ $money($line->amount) }}</td>
            </tr>
        @endforeach
    </table>

    <table class="gap-24">
        @foreach ($voucher->totals as $total)
            <tr class="rule"><td>{{ $total['label'] }}</td><td class="num">{{ $money($total['amount']) }}</td></tr>
        @endforeach
        <tr class="total">
            <td>{{ $row->owedToEstatell ? __('Owed to Estatell') : __('Payable to property') }}</td>
            <td class="num">{{ $money($row->amount) }}</td>
        </tr>
    </table>
@endsection
