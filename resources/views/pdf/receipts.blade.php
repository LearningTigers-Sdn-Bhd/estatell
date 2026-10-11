@extends('pdf.layout')

@php
    /** @var list<\App\Services\Payments\ReceiptDocument> $receipts */
@endphp

@section('title', __('Receipts'))

@section('content')
    @foreach ($receipts as $receipt)
        <div @if (! $loop->first) style="page-break-before: always;" @endif>
            @include('pdf.partials.receipt-body', ['receipt' => $receipt])
        </div>
    @endforeach
@endsection
