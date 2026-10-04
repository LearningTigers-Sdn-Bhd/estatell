@extends('pdf.layout')

@php
    /** @var \App\Data\CityLedger\AgingReportData $report */
    $money = fn (string $amount): string => number_format((float) $amount, 2);
@endphp

@section('title', __('City ledger aging'))

@section('content')
    <table>
        <tr>
            <td>
                <div class="heading">{{ $letterhead['name'] }}</div>
                @foreach ($letterhead['addressLines'] as $line)
                    <div class="small">{{ $line }}</div>
                @endforeach
            </td>
            <td class="num">
                <div class="title">{{ __('City ledger aging') }}</div>
                <div class="small muted">{{ __('As at :date', ['date' => \Carbon\CarbonImmutable::parse($report->asAt)->format('j M Y')]) }}</div>
                @if ($currency !== '')
                    <div class="small muted">{{ $currency }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="gap-24">
        <tr class="rule">
            <th>{{ __('Company') }}</th>
            <th class="num">{{ __('Current') }}</th>
            <th class="num">1-30</th>
            <th class="num">31-60</th>
            <th class="num">61-90</th>
            <th class="num">{{ __('Over 90') }}</th>
            <th class="num">{{ __('Credit') }}</th>
            <th class="num">{{ __('Total') }}</th>
        </tr>
        @foreach ($report->rows as $row)
            <tr class="rule">
                <td>{{ $row->companyName }}</td>
                <td class="num">{{ $money($row->current) }}</td>
                <td class="num">{{ $money($row->days1to30) }}</td>
                <td class="num">{{ $money($row->days31to60) }}</td>
                <td class="num">{{ $money($row->days61to90) }}</td>
                <td class="num">{{ $money($row->over90) }}</td>
                <td class="num">{{ $money($row->credit) }}</td>
                <td class="num">{{ $money($row->total) }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td>{{ __('Total') }}</td>
            <td class="num">{{ $money($report->totals->current) }}</td>
            <td class="num">{{ $money($report->totals->days1to30) }}</td>
            <td class="num">{{ $money($report->totals->days31to60) }}</td>
            <td class="num">{{ $money($report->totals->days61to90) }}</td>
            <td class="num">{{ $money($report->totals->over90) }}</td>
            <td class="num">{{ $money($report->totals->credit) }}</td>
            <td class="num">{{ $money($report->totals->total) }}</td>
        </tr>
    </table>
@endsection
