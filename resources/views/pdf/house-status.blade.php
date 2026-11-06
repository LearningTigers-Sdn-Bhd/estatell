@extends('pdf.layout')

@php
    /**
     * @var string $title
     * @var string $dates
     * @var string $workingDate
     * @var bool $selectedOnly
     * @var list<array{title: string, kind: string, warning: bool, total: string, tiles: list<\App\Data\Reports\HouseStatusRow>}> $groups
     * @var list<\App\Data\Reports\HousekeepingItemRow> $items
     */
    $colours = [
        'out' => '#dc2626',
        'move_out' => '#dc2626',
        'in' => '#111827',
        'move_in' => '#111827',
        'occ' => '#2563eb',
        'management' => '#7e22ce',
    ];
    $perLine = 10;
@endphp

@section('title', $title)

@push('head')
    <style>
        .hs { width: 100%; border-collapse: collapse; }
        .hs td { border: 1px solid #9ca3af; vertical-align: middle; }
        .hs .group { width: 13%; text-align: center; font-size: 13pt; font-weight: bold; padding: 4pt; }
        .hs .sum { width: 9%; text-align: center; font-size: 8pt; padding: 3pt; }
        .hs .sum strong { font-size: 11pt; }
        .tiles { width: 100%; border-collapse: collapse; }
        .tiles td { border: none; border-right: 1px solid #e5e7eb; width: 10%; text-align: center; padding: 2pt 1pt; vertical-align: top; }
        .tile-pax { font-size: 7pt; }
        .tile-label { font-size: 7pt; text-transform: uppercase; }
        .tile-room { font-size: 13pt; font-weight: bold; }
        .tile-flag { font-size: 6.5pt; text-transform: uppercase; }
        .struck { text-decoration: line-through; }
        .banner { background: #fde68a; text-align: center; font-size: 15pt; font-weight: bold; padding: 4pt; border: 1px solid #9ca3af; }
        .working { background: #fb923c; text-align: center; font-weight: bold; padding: 2pt; border: 1px solid #9ca3af; border-top: none; }
        .amenities { width: 100%; border-collapse: collapse; margin-top: 12pt; }
        .amenities th, .amenities td { border: 1px solid #9ca3af; padding: 3pt; font-size: 8pt; }
        .amenities th { background: #e5e7eb; }
        .amenities .head { background: #fde68a; text-align: center; font-weight: bold; }
    </style>
@endpush

@section('content')
    <div class="banner">{{ mb_strtoupper($title) }}</div>
    <div class="working">{{ __('Working date: :date', ['date' => $workingDate]) }}</div>
    @if ($selectedOnly)
        <div class="small muted">{{ __('Selected rows only') }}</div>
    @endif

    <table class="hs">
        <tbody>
            @forelse ($groups as $group)
                @php $colour = $group['warning'] ? '#b45309' : ($colours[$group['kind']] ?? '#111827'); @endphp
                <tr>
                    <td class="group" style="color: {{ $colour }}">{{ mb_strtoupper($group['title']) }}</td>
                    <td>
                        <table class="tiles">
                            @foreach (array_chunk($group['tiles'], $perLine) as $line)
                                <tr>
                                    @foreach ($line as $tile)
                                        <td style="color: {{ $colours[$tile->kind] ?? '#111827' }}">
                                            <div class="tile-pax">{{ $tile->pax > 0 ? 'X'.$tile->pax : '' }}</div>
                                            <div class="tile-label">{{ $tile->label }}</div>
                                            <div class="tile-room @if ($tile->checked_out) struck @endif">{{ $tile->room }}</div>
                                            @foreach ($tile->flags as $flag)
                                                <div class="tile-flag">{{ $flag }}</div>
                                            @endforeach
                                            @if ($tile->cleaningText() !== '')
                                                <div class="tile-flag">{{ $tile->cleaningText() }}</div>
                                            @endif
                                            @if ($tile->remark !== null)
                                                <div class="tile-flag">{{ \Illuminate\Support\Str::limit($tile->remark, 24) }}</div>
                                            @endif
                                        </td>
                                    @endforeach
                                    @for ($empty = count($line); $empty < $perLine; $empty++)
                                        <td></td>
                                    @endfor
                                </tr>
                            @endforeach
                        </table>
                    </td>
                    <td class="sum" style="color: {{ $colour }}"><strong>{{ $group['total'] }}</strong></td>
                </tr>
            @empty
                <tr>
                    <td class="muted" style="padding: 6pt">{{ __('No rooms on this date.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="amenities">
        <thead>
            <tr><td colspan="4" class="head">{{ __('Extra amenities / remark') }}</td></tr>
            <tr>
                <th style="width: 12%">{{ __('Room no') }}</th>
                <th>{{ __('Description / remark') }}</th>
                <th style="width: 8%">{{ __('Qty') }}</th>
                <th style="width: 25%">{{ __('Assign (runner)') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    <td>{{ $item->room }}</td>
                    <td>{{ $item->item }} · {{ $item->action }}</td>
                    <td style="text-align: right">{{ $item->quantity }}</td>
                    <td></td>
                </tr>
            @empty
                <tr><td>&nbsp;</td><td></td><td></td><td></td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="small" style="margin-top: 8pt"><strong>{{ __('Assigned by: :name', ['name' => auth()->user()?->name ?? '']) }}</strong></p>
@endsection
