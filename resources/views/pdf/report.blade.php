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
     * @var \App\Enums\PageOrientation $orientation
     * @var list<array{title: string, subtotal: string|null, warning: bool, rows: list<\App\Data\Reports\ReportRow>}>|null $sections
     */
@endphp

@section('title', $title)

@php
    // A report with more columns than its orientation fits (PageOrientation::wideTableColumns()) prints tight: table
    // text 1 pt smaller, 4pt between columns in every table style, and a date-time cell with the date over the time.
    // Dates, times, document numbers and phones never wrap (dompdf would break "SAR-26500064" after the hyphen).
    // A money heading puts its currency on a second line, so the column is as wide as its label, not "Label (MYR)".
    $isWide = count($columns) > $orientation->wideTableColumns();
    $dateTimeKeys = ['checked_in_at', 'checked_out_at', 'due_at', 'started'];
    $noWrapKeys = [
        // Document and ID numbers
        'reservation', 'booking', 'receipt', 'folio', 'invoice', 'refund_of', 'registration', 'code', 'id_number', 'room', 'phone',
        // Short statuses ("Checked in")
        'status',
        // Dates and date ranges
        'date', 'period', 'received_on', 'date_of_birth', 'arrival', 'departure', 'arrival_date', 'departure_date', 'checked_in_date',
        'checked_out_date', 'started_date', 'completed_date', 'service_date', 'stay',
        // Times and date-times
        'arrival_time', 'departure_time', 'checked_in_time', 'checked_out_time', 'started_time', 'completed_time', 'checked_in_at',
        'checked_out_at', 'due_at', 'in', 'out', 'started',
    ];
    $splitsCurrency = fn ($column): bool => $column->money && $currency !== '';
    // An empty cell prints "-". The ID ("MyKad · 031106-01-0122") prints the type over the number, and the number
    // never wraps, so the column is as wide as the number.
    $cellText = function ($column, array $cells) use ($isWide, $dateTimeKeys): \Illuminate\Support\HtmlString|string {
        $value = (string) ($cells[$column->key] ?? '');

        if ($value === '') {
            return '-';
        }

        if ($column->key === 'identity' && str_contains($value, ' · ')) {
            [$type, $number] = explode(' · ', $value, 2);

            return new \Illuminate\Support\HtmlString(e($type).'<br><span class="nowrap">'.e($number).'</span>');
        }

        // "11 Nov 2026 07:16" or "12 Nov 2026, 14:00"; a time alone stays as it is.
        if ($isWide && in_array($column->key, $dateTimeKeys, true) && preg_match('/^(.+?),? (\d{1,2}:\d{2})$/', $value, $parts) === 1) {
            return new \Illuminate\Support\HtmlString('<span class="nowrap">'.e($parts[1]).'</span><br><span class="nowrap">'.e($parts[2]).'</span>');
        }

        return $value;
    };
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
        .center { text-align: center; }
        .keep-together { page-break-inside: avoid; }
        @if ($isWide)
            .data { font-size: {{ ($print?->fontSize->points() ?? 9.0) - 1 }}pt; }
            @if (($print?->tableStyle ?? \App\Enums\PdfTableStyle::Minimal) !== \App\Enums\PdfTableStyle::Minimal)
                .data td, .data th, .data td:last-child, .data th:last-child { padding-left: 2pt; padding-right: 2pt; }
            @endif
        @endif
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
        @if ($selectedOnly)
            <div class="small muted">{{ __('Selected rows only') }}</div>
        @endif
    @endcomponent

    @if ((($sections ?? null)) === null)
    <table class="gap-24 data">
        <thead>
            <tr class="rule">
                @foreach ($columns as $column)
                    <th @class($cellClass($column))>@if ($splitsCurrency($column)){{ $column->label }}<br>({{ $currency }})@else{{ $column->heading($currency) }}@endif</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php $cells = $row->cells(); @endphp
                <tr class="rule">
                    @foreach ($columns as $column)
                        <td @class($cellClass($column))>{{ $cellText($column, $cells) }}</td>
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
                                <th @class($cellClass($column))>@if ($splitsCurrency($column)){{ $column->label }}<br>({{ $currency }})@else{{ $column->heading($currency) }}@endif</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($section['rows'] as $row)
                            @php $cells = $row->cells(); @endphp
                            <tr class="rule">
                                @foreach ($columns as $column)
                                    <td @class($cellClass($column))>{{ $cellText($column, $cells) }}</td>
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
        {{-- The title and the table stay on one page; a breakdown longer than a page still splits. --}}
        <div class="keep-together">
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
        </div>
    @endif
@endsection
