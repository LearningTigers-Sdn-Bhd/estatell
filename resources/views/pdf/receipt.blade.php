@extends('pdf.layout')

@section('title', __('Receipt :number', ['number' => $receipt->receiptNumber]))

@section('content')
    @include('pdf.partials.receipt-body', ['receipt' => $receipt])
@endsection
