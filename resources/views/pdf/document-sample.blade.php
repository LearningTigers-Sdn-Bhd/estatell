@extends('pdf.layout')

@php
    /** @var \App\Services\Property\PrintLayout $print */
    /** @var \App\Services\Folio\DocumentLineGroups $groups */
    /** @var \Brick\Money\Currency $currency */
    /** @var \App\Models\PrintSetting $settings */
    $strip = [
        __('Bill to') => 'Acme Travel Sdn Bhd',
        __('Stay') => '12–15 Oct 2026',
        __('Guests') => __(':count adults', ['count' => 2]),
        __('Rooms') => '2',
    ];
@endphp

@section('title', __('Sample booking summary'))

@section('content')
    @component('pdf.partials.letterhead', ['print' => $print])
        <div class="title">{{ __('Booking summary') }}</div>
        <div class="small muted">{{ __('Sample') }}</div>
        <div class="small muted">{{ __('Not a tax invoice') }}</div>
    @endcomponent

    <table class="gap-16">
        <tr>
            @foreach (array_filter($strip, filled(...)) as $label => $value)
                <td>
                    <div class="small muted">{{ $label }}</div>
                    <div>{{ $value }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    @include('pdf.partials.document-lines', ['groups' => $groups, 'currency' => $currency, 'settings' => $settings])

    @if ($settings->show_cancellation_policy)
        <div class="heading gap-24">{{ __('Cancellation policy') }}</div>
        <div class="small">{{ __('Free cancellation until 2 days before arrival. After that, one night is charged.') }}</div>
    @endif

    @if (filled($settings->bank_details))
        <div class="heading gap-16">{{ __('Payment details') }}</div>
        <div class="small">{!! nl2br(e($settings->bank_details)) !!}</div>
    @endif
@endsection
