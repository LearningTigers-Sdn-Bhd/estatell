@extends('pdf.layout')

@php
    /**
     * @var \App\Models\NightAudit $audit
     * @var \App\Services\NightAudit\AuditSummary $summary
     * @var \Illuminate\Support\Collection<int, \App\Models\NightAuditStep> $steps
     * @var string|null $completedAt
     * @var \Brick\Money\Money $roomRevenue
     */
    $money = fn (\Brick\Money\Money $amount): string => number_format((float) (string) $amount->getAmount(), 2);
    $stats = $summary->statistics;
    $date = $summary->businessDate->format('j M Y');
    $title = __('Night audit').' — '.$date;
    $paymentCount = array_sum(array_column($summary->payments, 'count'));
    $paymentTotal = array_reduce($summary->payments, fn (\Brick\Money\Money $sum, array $row): \Brick\Money\Money => $sum->plus($row['amount']), \Brick\Money\Money::zero($summary->currency))->minus($summary->refunds->abs());
    $revenueTotal = array_reduce($summary->revenue, fn (\Brick\Money\Money $sum, array $row): \Brick\Money\Money => $sum->plus($row['amount']), \Brick\Money\Money::zero($summary->currency));
@endphp

@section('title', $title)

@section('content')
    @component('pdf.partials.letterhead', ['print' => $print])
        <div class="title">{{ $title }}</div>
        <div class="small muted">{{ $summary->currency }}</div>
    @endcomponent

    <table class="gap-16">
        <tr>
            <td class="label">{{ __('Closed by') }}</td>
            <td>{{ $audit->businessDate->closedBy?->name ?? $audit->runBy?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Trigger') }}</td>
            <td>{{ $audit->trigger->label() }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('Completed') }}</td>
            <td>{{ $completedAt ?? '—' }}</td>
        </tr>
        @if ($audit->forced)
            <tr>
                <td class="label">{{ __('Forced') }}</td>
                <td>{{ __('Forced: :reason', ['reason' => $audit->force_reason ?? '']) }}</td>
            </tr>
        @endif
    </table>

    <div class="heading gap-24">{{ __('Statistics') }}</div>
    @if ($stats === null)
        <div class="muted gap-8">{{ __('No statistics were saved for this date.') }}</div>
    @else
        <table class="gap-8">
            <tr class="rule"><td>{{ __('Rooms available') }}</td><td class="num">{{ $stats->rooms_available }}</td></tr>
            <tr class="rule"><td>{{ __('Rooms sold') }}</td><td class="num">{{ $stats->rooms_sold }}</td></tr>
            <tr class="rule"><td>{{ __('Beds available') }}</td><td class="num">{{ $stats->beds_available }}</td></tr>
            <tr class="rule"><td>{{ __('Beds sold') }}</td><td class="num">{{ $stats->beds_sold }}</td></tr>
            <tr class="rule"><td>{{ __('Occupancy %') }}</td><td class="num">{{ number_format((float) $stats->occupancy, 2) }}</td></tr>
            <tr class="rule"><td>{{ __('ADR') }}</td><td class="num">{{ number_format((float) $stats->adr, 2) }}</td></tr>
            <tr class="rule"><td>{{ __('RevPAR') }}</td><td class="num">{{ number_format((float) $stats->revpar, 2) }}</td></tr>
            <tr class="rule"><td>{{ __('Room revenue') }}</td><td class="num">{{ $money($roomRevenue) }}</td></tr>
            <tr class="rule"><td>{{ __('Arrivals') }}</td><td class="num">{{ $stats->arrivals }}</td></tr>
            <tr class="rule"><td>{{ __('Departures') }}</td><td class="num">{{ $stats->departures }}</td></tr>
            <tr class="rule"><td>{{ __('No-shows') }}</td><td class="num">{{ $stats->no_shows }}</td></tr>
            <tr class="rule"><td>{{ __('In-house guests') }}</td><td class="num">{{ $stats->in_house_guests }}</td></tr>
        </table>
    @endif

    <div class="heading gap-24">{{ __('Revenue by code') }}</div>
    @if (count($summary->revenue) === 0)
        <div class="muted gap-8">{{ __('No revenue was posted on this date.') }}</div>
    @else
        <table class="gap-8">
            <thead>
                <tr class="rule">
                    <th>{{ __('Code') }}</th>
                    <th>{{ __('Description') }}</th>
                    <th class="num">{{ __('Lines') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary->revenue as $row)
                    <tr class="rule">
                        <td>{{ $row['code'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td class="num">{{ $row['lines'] }}</td>
                        <td class="num">{{ $money($row['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="total">
                    <td colspan="2">{{ __('Total') }}</td>
                    <td class="num">{{ array_sum(array_column($summary->revenue, 'lines')) }}</td>
                    <td class="num">{{ $money($revenueTotal) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="heading gap-24">{{ __('Payments by method') }}</div>
    @if (count($summary->payments) === 0 && $summary->refunds->isZero())
        <div class="muted gap-8">{{ __('No payments were received on this date.') }}</div>
    @else
        <table class="gap-8">
            <thead>
                <tr class="rule">
                    <th>{{ __('Method') }}</th>
                    <th class="num">{{ __('Count') }}</th>
                    <th class="num">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary->payments as $row)
                    <tr class="rule">
                        <td>{{ $row['name'] }}</td>
                        <td class="num">{{ $row['count'] }}</td>
                        <td class="num">{{ $money($row['amount']) }}</td>
                    </tr>
                @endforeach
                @unless ($summary->refunds->isZero())
                    <tr class="rule">
                        <td>{{ __('Refunds') }}</td>
                        <td class="num"></td>
                        <td class="num">-{{ $money($summary->refunds->abs()) }}</td>
                    </tr>
                @endunless
            </tbody>
            <tfoot>
                <tr class="total">
                    <td>{{ __('Total') }}</td>
                    <td class="num">{{ $paymentCount }}</td>
                    <td class="num">{{ $money($paymentTotal) }}</td>
                </tr>
            </tfoot>
        </table>
    @endif

    @if ($summary->meals->isNotEmpty())
        <div class="heading gap-24">{{ __('Meals for :date (as at printing)', ['date' => $summary->mealDate->format('j M Y')]) }}</div>
        <table class="gap-8">
            <thead>
                <tr class="rule">
                    <th>{{ __('Meal') }}</th>
                    <th class="num">{{ __('Adults') }}</th>
                    <th class="num">{{ __('Children') }}</th>
                    <th class="num">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary->meals as $meal)
                    <tr class="rule">
                        <td>{{ $meal->mealPeriodName }}</td>
                        <td class="num">{{ $meal->adults }}</td>
                        <td class="num">{{ $meal->children }}</td>
                        <td class="num">{{ $meal->guests }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="heading gap-24">{{ __('Exceptions') }}</div>
    @if ($summary->exceptions->isEmpty())
        <div class="muted gap-8">{{ __('This audit had no exceptions.') }}</div>
    @else
        <table class="gap-8">
            <thead>
                <tr class="rule">
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Reservation') }}</th>
                    <th>{{ __('Message') }}</th>
                    <th>{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary->exceptions as $exception)
                    <tr class="rule">
                        <td>{{ $exception->type->label() }}</td>
                        <td>{{ $exception->reservationUnit?->reservation->reference ?? __('Property') }}</td>
                        <td>{{ app(\App\Services\NightAudit\ReadinessAdvice::class)->reason($exception->message) }}</td>
                        <td>{{ $exception->resolved_at === null ? __('Open') : __('Resolved') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="heading gap-24">{{ __('Audit steps') }}</div>
    <table class="gap-8">
        <thead>
            <tr class="rule">
                <th>{{ __('Step') }}</th>
                <th>{{ __('Status') }}</th>
                <th class="num">{{ __('Processed') }}</th>
                <th class="num">{{ __('Failed') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($steps as $step)
                <tr class="rule">
                    <td>{{ $step->step->label() }}</td>
                    <td>{{ $step->status->label() }}</td>
                    <td class="num">{{ $step->processed_count }}</td>
                    <td class="num">{{ $step->failed_count }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
