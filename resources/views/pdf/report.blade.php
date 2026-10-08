@extends('pdf.layout')

@php
    /**
     * @var string $title
     * @var string $dates
     * @var string|null $group
     * @var string $currency
     * @var list<\App\Data\Reports\ReportLayoutColumnData> $columns
     * @var list<\App\Data\Reports\ReportRow> $rows
     * @var \App\Data\Reports\ReportTotals|null $totals
     * @var \App\Data\Reports\ReportBreakdown|null $breakdown
     * @var bool $selectedOnly
     */
@endphp

@section('title', $title)

@push('head')
    <style>
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
@endpush

@section('content')
    @component('pdf.partials.letterhead', ['print' => $print])
        <div class="title">{{ $title }}</div>
        <div class="small muted">{{ $dates }}</div>
        @if ($group !== null)
            <div class="small muted">{{ __('By :group', ['group' => mb_strtolower($group)]) }}</div>
        @endif
        @if ($currency !== '')
            <div class="small muted">{{ $currency }}</div>
        @endif
        @if ($selectedOnly)
            <div class="small muted">{{ __('Selected rows only') }}</div>
        @endif
    @endcomponent

    <table class="gap-24">
        <thead>
            <tr class="rule">
                @foreach ($columns as $column)
                    <th @class(['num' => $column->align === \App\Enums\ColumnAlign::Right])>{{ $column->label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php $cells = $row->cells(); @endphp
                <tr class="rule">
                    @foreach ($columns as $column)
                        <td @class(['num' => $column->align === \App\Enums\ColumnAlign::Right])>{{ $cells[$column->key] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) }}" class="muted">{{ __('No rows for these dates.') }}</td>
                </tr>
            @endforelse
        </tbody>
        @if ($totals !== null && count($rows) > 0)
            @php $totalCells = $totals->cellsFor($columns); @endphp
            <tfoot>
                <tr class="rule">
                    @foreach ($columns as $column)
                        <td @class(['num' => $column->align === \App\Enums\ColumnAlign::Right])><strong>{{ $totalCells[$column->key] }}</strong></td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    @if ($breakdown !== null && count($breakdown->rows) > 0)
        <div class="gap-24"><strong>{{ $breakdown->title }}</strong></div>
        <table>
            <thead>
                <tr class="rule">
                    @foreach ($breakdown->headings as $index => $heading)
                        <th @class(['num' => $index > 0])>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($breakdown->rows as $line)
                    <tr class="rule">
                        @foreach ($line as $index => $cell)
                            <td @class(['num' => $index > 0])>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="rule">
                    @foreach ($breakdown->total as $index => $cell)
                        <td @class(['num' => $index > 0])><strong>{{ $cell }}</strong></td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    @endif
@endsection
