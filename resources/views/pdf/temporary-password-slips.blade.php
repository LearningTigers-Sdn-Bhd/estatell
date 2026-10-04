@extends('pdf.layout')

@php
    /** @var \Illuminate\Support\Collection<int, \App\Data\Staff\TemporaryPasswordSlipData> $slips */
    $pages = $slips->chunk(8);
@endphp

@section('title', __('Sign-in slips'))

@section('content')
    <style>
        .slips td { width: 50%; height: 168pt; padding: 12pt; border: 0.5pt dashed #9a9a9a; }
        .password { font-size: 12pt; font-weight: bold; letter-spacing: 0.5pt; }
    </style>

    @foreach ($pages as $page)
        <table class="slips" @if (! $loop->first) style="page-break-before: always;" @endif>
            @foreach ($page->values()->chunk(2) as $pair)
                <tr>
                    @foreach ($pair as $slip)
                        <td>
                            <div class="heading">{{ $slip->property }}</div>
                            <div>{{ $slip->name }}@if ($slip->role !== null) <span class="muted">· {{ $slip->role }}</span>@endif</div>
                            <div class="gap-8 small muted">{{ __('1. Open') }}</div>
                            <div>{{ $slip->loginUrl }}</div>
                            <div class="gap-8 small muted">{{ __('2. Email') }}</div>
                            <div>{{ $slip->email }}</div>
                            <div class="gap-8 small muted">{{ __('3. Temp password') }}</div>
                            <div class="password">{{ $slip->temporaryPassword }}</div>
                            <div class="gap-8 small muted">{{ __('4. Set your own password when asked (expires :date)', ['date' => $slip->expiresOn]) }}</div>
                        </td>
                    @endforeach
                    @if ($pair->count() === 1)
                        <td style="border: none;"></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endforeach
@endsection
