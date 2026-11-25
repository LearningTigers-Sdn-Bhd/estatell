<?php

namespace App\Data\AiAssistant;

use App\Data\Property\CurrencyData;
use App\Models\Property;
use App\Services\AiAssistant\AiMonthlyCap;
use App\Services\AiAssistant\AiUsageReport;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Settings › AI assistant › Usage for one month (`Y-m`, property time zone): estimated spend in US dollars against the
 * cap, turns and threads, then by place, language, outcome and tool, and the bookings made from quotes with their room
 * total in the property currency. Counts and amounts only, never message text. `thisMonth` is the latest month the
 * picker offers. See docs/35-ai-assistant/04-usage-and-cost.md#usage-tab.
 */
class AiUsageData extends Data
{
    /**
     * @param  list<AiUsagePlaceData>  $places
     * @param  list<AiUsageLanguageData>  $languages
     * @param  list<AiUsageOutcomeData>  $outcomes
     * @param  list<AiUsageToolData>  $tools
     * @param  list<AiUsageBookingData>  $bookings
     */
    public function __construct(
        public string $month,
        public string $thisMonth,
        public string $spentUsd,
        public ?string $capUsd,
        public ?int $capPercent,
        public int $turns,
        public int $threads,
        public array $places,
        public array $languages,
        public array $outcomes,
        public array $tools,
        public array $bookings,
        public string $bookedRoomTotal,
        public ?CurrencyData $currency,
    ) {}

    public static function forMonth(Property $property, CarbonImmutable $monthStart): self
    {
        $report = app(AiUsageReport::class)->forMonth($property, $monthStart);
        $cap = app(AiMonthlyCap::class);
        $capMicros = $property->settings?->ai_monthly_cap_micros;
        $bookedRoomTotal = array_reduce($report['bookings'], fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['roomTotal']), BigDecimal::zero());

        return new self(
            month: $monthStart->format('Y-m'),
            thisMonth: now($monthStart->getTimezone())->format('Y-m'),
            spentUsd: (string) $cap->toDollars($report['spentMicros']),
            capUsd: $cap->toDollars($capMicros),
            capPercent: $capMicros === null || $capMicros === 0 ? null : intdiv($report['spentMicros'] * 100, $capMicros),
            turns: $report['turns'],
            threads: $report['threads'],
            places: array_map(fn (array $row): AiUsagePlaceData => AiUsagePlaceData::fromRow($row['place'], $row['turns'], (string) $cap->toDollars($row['spentMicros'])), $report['places']),
            languages: array_map(fn (array $row): AiUsageLanguageData => new AiUsageLanguageData($row['language'], $row['threads']), $report['languages']),
            outcomes: array_map(fn (array $row): AiUsageOutcomeData => AiUsageOutcomeData::fromRow($row['outcome'], $row['turns']), $report['outcomes']),
            tools: array_map(fn (array $row): AiUsageToolData => AiUsageToolData::fromRow($row['tool'], $row['runs'], $row['failures']), $report['tools']),
            bookings: array_map(fn (array $row): AiUsageBookingData => AiUsageBookingData::fromReservation($row['reservation'], $row['roomTotal'], $monthStart->getTimezone()->getName()), $report['bookings']),
            bookedRoomTotal: $bookedRoomTotal->toScale(2)->__toString(),
            currency: CurrencyData::forProperty($property),
        );
    }
}
