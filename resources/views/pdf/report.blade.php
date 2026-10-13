@extends('pdf.layout')

@php
    /**
     * @var string $title
     * @var string $dates
     * @var string|null $group
     * @var string|null $groupCaption
     * @var string|null $note
     * @var string $currency
     * @var list<\App\Data\Reports\ReportLayoutColumnData> $columns
     * @var list<\App\Data\Reports\ReportRow> $rows
     * @var \App\Data\Reports\ReportTotals|null $totals
     * @var \App\Data\Reports\ReportBreakdown|null $breakdown
     * @var bool $selectedOnly
     * @var list<array{title: string, subtotal: string|null, warning: bool, rows: list<\App\Data\Reports\ReportRow>}>|null $sections
     */
@endphp

@section('title', $title)

@php
    // A report with many columns prints its table text 1 pt smaller. Dates, times, references and phones never wrap.
    $isWide = count($columns) > 9;
    $noWrapKeys = ['reservation', 'room', 'date_of_birth', 'phone', 'arrival', 'departure', 'checked_in_at', 'checked_out_at'];
    $alignClass = fn ($column): array => [
        'num' => $column->align === \App\Enums\ColumnAlign::Right,
        'center' => $column->align === \App\Enums\ColumnAlign::Center,
    ];
    $cellClass = fn ($column): array => [
        ...$alignClass($column),
        'nowrap' => in_array($column->key, $noWrapKeys, true),
    ];
@endphp

@push('head')
    <style>
        .data td, .data th { padding-right: 4pt; }
        .data td:last-child, .data th:last-child { padding-right: 0; }
        .nowrap { white-space: nowrap; }
        .center { text-align: center; }
        @if ($isWide)
            .data { font-size: {{ ($print?->fontSize->points() ?? 9.0) - 1 }}pt; }
        @endif
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
@endpush

@section('content')
    @component('pdf.partials.letterhead', ['print' => $print])
        <div class="title">{{ $title }}</div>
        <div class="small muted">{{ $dates }}</div>
        @if ($groupCaption !== null)
            <div class="small muted">{{ $groupCaption }}</div>
        @endif
        @if ($note !== null)
            <div class="small muted">{{ $note }}</div>
        @endif
        @if ($currency !== '')
            <div class="small muted">{{ $currency }}</div>
        @endif
        @if ($selectedOnly)
            <div class="small muted">{{ __('Selected rows only') }}</div>
        @endif
    @endcomponent

    @if ((($sections ?? null)) === null)
    <table class="gap-24 data">
        <thead>
            <tr class="rule">
                @foreach ($columns as $column)
                    <th @class($cellClass($column))>{{ $column->label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php $cells = $row->cells(); @endphp
                <tr class="rule">
                    @foreach ($columns as $column)
                        <td @class($cellClass($column))>{{ ($cells[$column->key] ?? '') !== '' ? $cells[$column->key] : '-' }}</td>
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
                        <td @class($alignClass($column))><strong>{{ $totalCells[$column->key] }}</strong></td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
    @else
        @forelse ($sections as $index => $section)
            <div @if ($index > 0) style="page-break-before: always" @endif>
                <div class="gap-24"><strong>{{ $section['title'] }}</strong></div>
                <table class="data">
                    <thead>
                        <tr class="rule">
                            @foreach ($columns as $column)
                                <th @class($cellClass($column))>{{ $column->label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($section['rows'] as $row)
                            @php $cells = $row->cells(); @endphp
                            <tr class="rule">
                                @foreach ($columns as $column)
                                    <td @class($cellClass($column))>{{ ($cells[$column->key] ?? '') !== '' ? $cells[$column->key] : '-' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($section['subtotal'] !== null)
                        <tfoot>
                            <tr class="rule">
                                <td colspan="{{ count($columns) }}" @if ($section['warning']) style="color: #b45309" @endif><strong>{{ $section['subtotal'] }}</strong></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        @empty
            <table class="data">
                <tbody>
                    <tr>
                        <td colspan="{{ count($columns) }}" class="muted">{{ __('No rows for these dates.') }}</td>
                    </tr>
                </tbody>
            </table>
        @endforelse
        @if ($totals !== null && count($rows) > 0)
            @php $totalCells = $totals->cellsFor($columns); @endphp
            <table class="gap-24 data">
                <tbody>
                    <tr class="rule">
                        @foreach ($columns as $column)
                            <td @class($alignClass($column))><strong>{{ $totalCells[$column->key] }}</strong></td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        @endif
    @endif

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
                @foreach ($breakdown->rows as $lineIndex => $line)
                    @php $isSubtotal = in_array($lineIndex, $breakdown->subtotalRows, true); @endphp
                    <tr class="rule">
                        @foreach ($line as $index => $cell)
                            <td @class(['num' => $index > 0, 'muted' => $isSubtotal])>
                                @if ($isSubtotal)<strong>{{ $cell }}</strong>@else{{ $cell }}@endif
                            </td>
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
