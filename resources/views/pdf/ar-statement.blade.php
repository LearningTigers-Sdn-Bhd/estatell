@extends('pdf.layout')

@php
    /** @var \App\Data\CityLedger\StatementData $statement */
    $money = fn (string $amount): string => number_format((float) $amount, 2);
    $date = fn (string $value): string => \Carbon\CarbonImmutable::parse($value)->format('j M Y');
@endphp

@section('title', __('Statement of account'))

@section('content')
    @component('pdf.partials.letterhead', ['print' => $print])
        <div class="title">{{ __('Statement of account') }}</div>
        <div class="small muted">{{ $date($statement->from) }} – {{ $date($statement->to) }}</div>
        @if ($currency !== '')
            <div class="small muted">{{ $currency }}</div>
        @endif
    @endcomponent

    <table class="gap-24">
        <tr>
            <td>
                <div class="small muted">{{ __('Account') }}</div>
                <div class="heading">{{ $company->name }}</div>
                @foreach ($billToLines as $line)
                    <div class="small">{{ $line }}</div>
                @endforeach
            </td>
        </tr>
    </table>

    <table class="gap-24">
        <thead>
            <tr class="rule">
                <th>{{ __('Date') }}</th>
                <th>{{ __('Document') }}</th>
                <th>{{ __('Details') }}</th>
                <th class="num">{{ __('Amount') }}</th>
                <th class="num">{{ __('Balance') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr class="rule">
                <td colspan="4">{{ __('Opening balance') }}</td>
                <td class="num">{{ $money($statement->openingBalance) }}</td>
            </tr>
            @foreach ($statement->lines as $line)
                <tr class="rule">
                    <td class="nowrap">{{ $date($line->date) }}</td>
                    <td>{{ $line->kindLabel }} {{ $line->number }}</td>
                    <td class="small">{{ $line->description }}</td>
                    <td class="num">{{ $money($line->amount) }}</td>
                    <td class="num">{{ $money($line->balance) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="4">{{ __('Closing balance') }}</td>
                <td class="num">{{ $money($statement->closingBalance) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="gap-24">
        <tr class="rule">
            <th>{{ __('Aging as at :date', ['date' => $date($statement->to)]) }}</th>
            <th class="num">{{ __('Current') }}</th>
            <th class="num">1-30</th>
            <th class="num">31-60</th>
            <th class="num">61-90</th>
            <th class="num">{{ __('Over 90') }}</th>
        </tr>
        <tr>
            <td class="small muted">{{ __('By current allocations') }}</td>
            <td class="num">{{ $money($statement->aging->current) }}</td>
            <td class="num">{{ $money($statement->aging->days1to30) }}</td>
            <td class="num">{{ $money($statement->aging->days31to60) }}</td>
            <td class="num">{{ $money($statement->aging->days61to90) }}</td>
            <td class="num">{{ $money($statement->aging->over90) }}</td>
        </tr>
    </table>
@endsection
