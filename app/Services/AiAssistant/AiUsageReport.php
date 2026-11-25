<?php

namespace App\Services\AiAssistant;

use App\Enums\AiOutcome;
use App\Enums\AiPlace;
use App\Enums\AiTool;
use App\Models\AiThread;
use App\Models\AiTurn;
use App\Models\AiTurnTool;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Reservations\StayTotal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the AI assistant did and cost in one calendar month (property time zone), from the usage log: totals, turns and
 * spend per place, threads per language, turns per outcome, bookings made from quotes with their room total, and runs
 * and failures per tool. Counts and amounts only, never message text. Cost is in micros of a US dollar.
 * See docs/35-ai-assistant/04-usage-and-cost.md#usage-tab.
 */
class AiUsageReport
{
    public function __construct(private readonly StayTotal $stayTotal) {}

    /**
     * The first moment of the month in the property's time zone. `$month` is `Y-m`; null is this month.
     */
    public function monthStart(Property $property, ?string $month): CarbonImmutable
    {
        $timeZone = $property->loadMissing('settings')->settings->time_zone ?? config('app.timezone');

        return $month === null
            ? now($timeZone)->startOfMonth()
            : CarbonImmutable::createFromFormat('!Y-m', $month, $timeZone)->startOfMonth();
    }

    /**
     * @return array{
     *     spentMicros: int,
     *     turns: int,
     *     threads: int,
     *     places: list<array{place: AiPlace, turns: int, spentMicros: int}>,
     *     languages: list<array{language: ?string, threads: int}>,
     *     outcomes: list<array{outcome: AiOutcome, turns: int}>,
     *     tools: list<array{tool: AiTool, runs: int, failures: int}>,
     *     bookings: list<array{reservation: Reservation, roomTotal: string}>,
     * }
     */
    public function forMonth(Property $property, CarbonImmutable $monthStart): array
    {
        $turns = fn (): Builder => AiTurn::query()
            ->where('property_id', $property->id)
            ->where('created_at', '>=', $monthStart->utc())
            ->where('created_at', '<', $monthStart->addMonth()->utc());

        $totals = $turns()->selectRaw('COUNT(*) AS turns, COALESCE(SUM(cost_micros), 0) AS spent, COUNT(DISTINCT ai_thread_id) AS threads')->toBase()->first();

        return [
            'spentMicros' => (int) $totals->spent,
            'turns' => (int) $totals->turns,
            'threads' => (int) $totals->threads,
            'places' => array_values($turns()
                ->selectRaw('place, COUNT(*) AS turns, COALESCE(SUM(cost_micros), 0) AS spent')
                ->groupBy('place')
                ->orderByDesc('turns')
                ->toBase()
                ->get()
                ->map(fn (object $row): array => ['place' => AiPlace::from($row->place), 'turns' => (int) $row->turns, 'spentMicros' => (int) $row->spent])
                ->all()),
            'languages' => array_values(AiThread::query()
                ->whereIn('id', $turns()->select('ai_thread_id'))
                ->selectRaw('language, COUNT(*) AS threads')
                ->groupBy('language')
                ->orderByDesc('threads')
                ->toBase()
                ->get()
                ->map(fn (object $row): array => ['language' => is_string($row->language) ? $row->language : null, 'threads' => (int) $row->threads])
                ->all()),
            'outcomes' => $this->outcomes($turns()->selectRaw('outcome, COUNT(*) AS turns')->groupBy('outcome')->toBase()->pluck('turns', 'outcome')),
            'tools' => array_values(AiTurnTool::query()
                ->whereIn('ai_turn_id', $turns()->select('id'))
                ->selectRaw('tool, COUNT(*) AS runs, COUNT(*) FILTER (WHERE NOT succeeded) AS failures')
                ->groupBy('tool')
                ->orderByDesc('runs')
                ->toBase()
                ->get()
                ->map(fn (object $row): array => ['tool' => AiTool::from($row->tool), 'runs' => (int) $row->runs, 'failures' => (int) $row->failures])
                ->all()),
            'bookings' => array_values(Reservation::query()
                ->where('property_id', $property->id)
                ->whereIn('id', $turns()->whereNotNull('reservation_id')->select('reservation_id'))
                ->oldest()
                ->get()
                ->map(fn (Reservation $reservation): array => ['reservation' => $reservation, 'roomTotal' => $this->stayTotal->forReservation($reservation)])
                ->all()),
        ];
    }

    /**
     * Every outcome in enum order, with zero for those the month did not have.
     *
     * @param  Collection<string, int|string>  $counts
     * @return list<array{outcome: AiOutcome, turns: int}>
     */
    private function outcomes(Collection $counts): array
    {
        return array_map(fn (AiOutcome $outcome): array => ['outcome' => $outcome, 'turns' => (int) ($counts[$outcome->value] ?? 0)], AiOutcome::cases());
    }
}
