@extends('pdf.layout')

@php
    /** @var \App\Services\CityLedger\ArInvoiceDocument $document */
    $money = fn (string $amount): string => trim($document->currency.' '.number_format((float) $amount, 2));
@endphp

@section('title', $document->title.' '.$document->number)

@section('content')
    <table>
        <tr>
            <td>
                <div class="heading">{{ $document->propertyName }}</div>
                @foreach ($document->addressLines as $line)
                    <div class="small">{{ $line }}</div>
                @endforeach
                @foreach ($document->contactLines as $line)
                    <div class="small">{{ $line }}</div>
                @endforeach
                @foreach ($document->registrationLines as $line)
                    <div class="small muted">{{ $line }}</div>
                @endforeach
            </td>
            <td class="num">
                <div class="title">{{ $document->title }}</div>
                <div>{{ $document->number }}</div>
                @if ($document->isVoid)
                    <div class="heading">{{ __('VOID') }}</div>
                @endif
            </td>
        </tr>
    </table>

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
                @if ($document->folioNumber !== null)
                    <div class="small muted gap-8">{{ __('Folio') }}</div>
                    <div>{{ $document->folioNumber }}</div>
                @endif
                @if ($document->creditFor !== null)
                    <div class="small muted gap-8">{{ __('Corrects invoice') }}</div>
                    <div>{{ $document->creditFor }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if ($document->lines !== [])
        <table class="gap-24">
            <tr class="rule">
                <th>{{ __('Date') }}</th>
                <th>{{ __('Description') }}</th>
                <th class="num">{{ __('Amount') }}</th>
            </tr>
            @foreach ($document->lines as [$date, $description, $amount])
                <tr class="rule">
                    <td>{{ $date }}</td>
                    <td>{{ $description }}</td>
                    <td class="num">{{ $money($amount) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($document->reason !== null)
        <p class="gap-24"><span class="muted">{{ __('Reason') }}:</span> {{ $document->reason }}</p>
    @endif

    <table class="gap-24">
        <tr class="total"><td>{{ __('Total') }}</td><td class="num">{{ $money($document->total) }}</td></tr>
    </table>
@endsection
