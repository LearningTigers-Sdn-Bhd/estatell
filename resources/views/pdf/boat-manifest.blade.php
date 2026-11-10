@extends('pdf.layout')

@php
    /**
     * @var string $title
     * @var string $sheetTitle
     * @var string $sheetDate
     * @var bool $selectedOnly
     * @var list<array{band: string, kind: string, heading: string, jetty: ?string, boat: ?string, crew: list<array{name: string, in_charge: bool}>, rows: list<\App\Data\Reports\BoatManifestRow>, pax: int}> $sections
     */
    $bands = ['out' => '#ea3323', 'in' => '#9dc384', 'not_set' => '#fbbf24'];
    $upper = fn (?string $text): string => mb_strtoupper((string) $text);
@endphp

@section('title', $title)

@push('head')
    <style>
        @page { margin: 20pt 24pt; }
        table.manifest { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.manifest td, table.manifest th { border: 0.75pt solid #1a1a1a; padding: 1pt 3pt; text-align: center; vertical-align: middle; font-size: 9pt; line-height: 1.15; }
        .sheet-title { background: #4f81ea; font-weight: bold; font-size: 13pt !important; }
        .sheet-date { background: #f3f3f3; font-weight: bold; font-size: 11pt !important; }
        .section { margin-top: 5pt; page-break-inside: avoid; }
        table.manifest tr.widths td { border: none; padding: 0; height: 0; font-size: 0; line-height: 0; }
        .band td, .band th { font-weight: bold; white-space: nowrap; }
        table.manifest td.sum { border: none; border-bottom: 1pt solid #1a1a1a; font-weight: bold; }
        table.manifest td.blank { border: none; }
        .crew-first { color: #ea3323; font-weight: bold; }
        .crew-next { color: #1d3fd1; font-weight: bold; }
        .tick { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; line-height: 1; }
    </style>
@endpush

@section('content')
    <table class="manifest">
        <tr><td class="sheet-title">{{ $sheetTitle }}</td></tr>
        <tr><td class="sheet-date">{{ $sheetDate }}</td></tr>
    </table>
    @if ($selectedOnly)
        <div class="small muted">{{ __('Selected rows only') }}</div>
    @endif

    @foreach ($sections as $section)
        @continue($selectedOnly && $section['rows'] === [])
        @php $band = 'background: '.$bands[$section['kind']].';'; @endphp
        <div class="section">
            <table class="manifest">
                {{-- dompdf ignores colgroup, and takes the column widths from the first row. --}}
                <tr class="widths">
                    @foreach ([8, 21, 22, 8, 15, 20, 6] as $width)
                        <td style="width: {{ $width }}%"></td>
                    @endforeach
                </tr>
                <tr class="band" style="{{ $band }}"><td>{{ $upper($section['band']) }}</td><td colspan="6">{{ $section['heading'] }}</td></tr>
                <tr class="band" style="{{ $band }}"><td>{{ __('JETTY') }}</td><td colspan="6">{{ $section['jetty'] === null ? '-' : $upper($section['jetty']) }}</td></tr>
                <tr class="band" style="{{ $band }}">
                    <th>{{ __('No') }}</th><th>{{ __('Guest') }}</th><th>{{ __('Agent') }}</th><th>{{ __('PAX') }}</th><th>{{ __('BOAT') }}</th><th>{{ __('Boat Asst') }}</th><th>{{ __('Tick') }}</th>
                </tr>
                @php $rows = $section['rows'] === [] ? [null] : $section['rows']; @endphp
                @foreach ($rows as $row)
                    @php
                        $boat = $row === null ? $section['boat'] : ($row->boat ?? $section['boat']);
                        $crewMembers = $section['crew'];
                    @endphp
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $row === null ? '-' : $upper($row->lead_guest) }}</td>
                        <td>{{ $row === null ? '-' : $upper($row->agent) }}</td>
                        <td>{{ $row === null ? '-' : __(':count PAX', ['count' => $row->total]) }}</td>
                        <td>{{ $boat === null ? '-' : $upper($boat) }}</td>
                        <td>
                            @forelse ($crewMembers as $member)
                                @if (! $loop->first)<strong> / </strong>@endif<span class="{{ $member['in_charge'] ? 'crew-first' : 'crew-next' }}">{{ $upper($member['name']) }}</span>
                            @empty
                                -
                            @endforelse
                        </td>
                        <td><span class="tick">{{ $row?->ticked ? '☑' : '☐' }}</span></td>
                    </tr>
                @endforeach
                <tr>
                    <td class="blank" colspan="3"></td>
                    <td class="sum">{{ $section['rows'] === [] ? '-' : __(':count PAX', ['count' => $section['pax']]) }}</td>
                    <td class="blank" colspan="3"></td>
                </tr>
            </table>
        </div>
    @endforeach
@endsection
