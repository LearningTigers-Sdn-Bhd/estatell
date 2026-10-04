@extends('pdf.layout')

@php
    /** @var \App\Services\Folio\FolioVoucherDocument $voucher */
    $money = fn (string $amount): string => trim($voucher->currency.' '.number_format((float) $amount, 2));
    $copies = [__('Guest copy'), __('Hotel copy')];
@endphp

@section('title', __('Voucher :folio', ['folio' => $voucher->folioNumber ?? $voucher->reservationReference]))

@section('content')
    @foreach ($copies as $copy)
        <div @if (! $loop->first) style="page-break-before: always;" @endif>
            <table>
                <tr>
                    <td>
                        <div class="heading">{{ $voucher->propertyName }}</div>
                        @foreach ($voucher->addressLines as $line)
                            <div class="small">{{ $line }}</div>
                        @endforeach
                        @foreach ($voucher->contactLines as $line)
                            <div class="small">{{ $line }}</div>
                        @endforeach
                        @foreach ($voucher->registrationLines as $line)
                            <div class="small muted">{{ $line }}</div>
                        @endforeach
                    </td>
                    <td class="num">
                        <div class="small muted">{{ $copy }}</div>
                        <div class="title">{{ __('Voucher') }}</div>
                        <div>{{ $voucher->folioNumber ?? $voucher->reservationReference }}</div>
                        <div class="small muted">{{ $voucher->issuedOn }}</div>
                    </td>
                </tr>
            </table>

            <table class="gap-24">
                <tr class="rule"><td class="label">{{ __('Guest') }}</td><td>{{ $voucher->guestName }}</td></tr>
                @if ($voucher->nationality !== null)
                    <tr class="rule"><td class="label">{{ __('Nationality') }}</td><td>{{ $voucher->nationality }}</td></tr>
                @endif
                <tr class="rule"><td class="label">{{ __('Reservation') }}</td><td>{{ $voucher->reservationReference }}</td></tr>
                @if ($voucher->stay !== '')
                    <tr class="rule"><td class="label">{{ __('Stay') }}</td><td>{{ $voucher->stay }}</td></tr>
                @endif
            </table>

            <table class="gap-24">
                <tr class="rule">
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Description') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                </tr>
                @foreach ($voucher->rows as $row)
                    <tr class="rule">
                        <td>{{ $row['level'] === 0 ? $row['date'] : '' }}</td>
                        <td @if ($row['level'] > 0) style="padding-left: {{ $row['level'] * 12 }}pt;" @endif>{{ $row['description'] }}</td>
                        <td class="num">{{ $money($row['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td colspan="2">{{ __('Total') }}</td><td class="num">{{ $money($voucher->total) }}</td></tr>
            </table>

            <table class="gap-24">
                <tr>
                    <td style="width: 50%; padding-top: 32pt;">{{ __('Guest signature') }} ____________________</td>
                    <td style="width: 50%; padding-top: 32pt;">{{ __('Staff signature') }} ____________________</td>
                </tr>
            </table>
        </div>
    @endforeach
@endsection
