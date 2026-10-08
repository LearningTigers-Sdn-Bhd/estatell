<?php

namespace App\Services\Reports;

use App\Enums\AuditStatus;
use App\Enums\BusinessDateStatus;
use App\Models\NightAudit;
use App\Models\Property;
use App\Services\NightAudit\AuditResultSummary;
use App\Services\Property\PrintedPdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The night audit packet: one fixed PDF for a Completed audit of a Closed business date, in Portrait with the
 * letterhead. The content comes from `AuditResultSummary`, with every exception of the audit, open and resolved. The
 * meals are the live count for the date after the business date and are labelled "as at printing". It reads only.
 * See docs/22-reports/01-core-reports.md.
 */
class NightAuditPacket
{
    public function __construct(private AuditResultSummary $summary, private DailyRevenueQuery $revenue, private PrintedPdf $printedPdf) {}

    /**
     * The Completed audit of the property for the business date, or a 404 when the date is not a date, has no audit,
     * its audit is not Completed or the date is not Closed.
     */
    public function audit(Property $property, string $date): NightAudit
    {
        $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && CarbonImmutable::createFromFormat('Y-m-d', $date)?->toDateString() === $date;

        abort_unless($valid, 404);

        return NightAudit::query()
            ->where('property_id', $property->id)
            ->where('status', AuditStatus::Completed->value)
            ->whereHas('businessDate', fn (Builder $dates) => $dates
                ->where('property_id', $property->id)
                ->whereDate('date', $date)
                ->where('status', BusinessDateStatus::Closed->value))
            ->firstOrFail();
    }

    public function make(NightAudit $audit): DomPdf
    {
        $audit->loadMissing('property.settings', 'businessDate.closedBy', 'runBy', 'steps');
        $property = $audit->property;
        $summary = $this->summary->forAudit($audit, allExceptions: true);
        $date = CarbonImmutable::parse($audit->businessDate->date->toDateString());
        $timeZone = $property->settings->time_zone ?? config('app.timezone');

        return $this->printedPdf->make($property, 'pdf.night-audit-packet', [
            'audit' => $audit,
            'summary' => $summary,
            'roomRevenue' => $this->revenue->roomRevenueBetween($property, $date, $date)[$date->toDateString()] ?? Money::zero($summary->currency),
            'steps' => $audit->steps->sortBy('id')->values(),
            'completedAt' => $audit->completed_at?->toImmutable()->setTimezone($timeZone)->format('j M Y H:i'),
        ]);
    }

    public function filename(NightAudit $audit): string
    {
        return 'night-audit-'.$audit->businessDate->date->toDateString().'.pdf';
    }
}
