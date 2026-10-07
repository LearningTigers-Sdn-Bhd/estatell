@extends('pdf.layout')

@php
    /** @var \App\Services\Property\PrintLayout $print */
    $rows = [
        ['RSV-1042', 'Aisyah binti Rahman', 'Deluxe King', '2', '480.00'],
        ['RSV-1043', 'Daniel Lim', 'Superior Twin', '1', '210.00'],
        ['RSV-1044', 'Priya Nair', 'Family Suite', '3', '1,050.00'],
        ['RSV-1045', 'Muhammad Hafiz', 'Dorm bed', '4', '160.00'],
    ];
@endphp

@section('title', __('Sample report'))

@section('content')
    @component('pdf.partials.letterhead', ['print' => $print])
        <div class="title">{{ __('Sample report') }}</div>
        <div class="small muted">{{ __('How your PDFs look') }}</div>
        @if ($currency !== '')
            <div class="small muted">{{ $currency }}</div>
        @endif
    @endcomponent

    <table class="gap-24">
        <tr class="rule">
            <th>{{ __('Reservation') }}</th>
            <th>{{ __('Guest') }}</th>
            <th>{{ __('Room type') }}</th>
            <th class="num">{{ __('Nights') }}</th>
            <th class="num">{{ __('Amount') }}</th>
        </tr>
        @foreach ($rows as [$reference, $guest, $roomType, $nights, $amount])
            <tr class="rule">
                <td>{{ $reference }}</td>
                <td>{{ $guest }}</td>
                <td>{{ $roomType }}</td>
                <td class="num">{{ $nights }}</td>
                <td class="num">{{ $amount }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td colspan="4">{{ __('Total') }}</td>
            <td class="num">1,900.00</td>
        </tr>
    </table>
@endsection
