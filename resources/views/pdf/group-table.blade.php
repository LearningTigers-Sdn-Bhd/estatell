@extends('pdf.layout')

@php
    /**
     * A group page as a table, on landscape A4 (GroupTableExport::pdf()).
     *
     * @var string $group
     * @var string $title
     * @var string $subtitle
     * @var list<string> $headings
     * @var list<list<string|int|float|null>> $rows
     * @var array<int, int> $totalRows
     * @var array<int, bool> $numeric
     * @var string $printedAt
     */
    $isWide = count($headings) > 10;
@endphp

@section('title', $group.' · '.$title)

@push('head')
    <style>
        @if ($isWide)
            body { font-size: 7.5pt; }
            td, th { padding: 3pt 3pt 3pt 0; }
        @endif
        .bold td { font-weight: bold; border-top: 1pt solid #1a1a1a; }
    </style>
@endpush

@section('content')
    <table class="letterhead">
        <tr>
            <td>
                <div class="heading">{{ $group }}</div>
                <div class="muted">{{ __('All properties') }}</div>
            </td>
            <td class="document-heading">
                <div class="title">{{ $title }}</div>
                <div>{{ $subtitle }}</div>
                <div class="muted small">{{ __('Printed :time', ['time' => $printedAt]) }}</div>
            </td>
        </tr>
    </table>

    <table class="gap-16">
        <thead>
            <tr class="rule">
                @foreach ($headings as $index => $heading)
                    <th class="{{ $numeric[$index] ? 'num' : '' }}">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $rowIndex => $row)
                <tr class="rule {{ isset($totalRows[$rowIndex]) ? 'bold' : '' }}">
                    @foreach ($row as $index => $cell)
                        <td class="{{ ($numeric[$index] ?? false) ? 'num' : '' }}">{{ $cell === null || $cell === '' ? '-' : $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headings) }}" class="muted">{{ __('Nothing to show for these dates.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
