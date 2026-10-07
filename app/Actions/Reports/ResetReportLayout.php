<?php

namespace App\Actions\Reports;

use App\Enums\ReportKey;
use App\Models\Property;
use App\Models\ReportLayout;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetReportLayout
{
    /**
     * Deletes the stored layout of a report, so the code defaults apply again. Does nothing when there is none.
     */
    public function __invoke(User $actor, Property $property, ReportKey $report): void
    {
        DB::transaction(function () use ($actor, $property, $report): void {
            $layout = ReportLayout::query()
                ->where('property_id', $property->id)
                ->where('report_key', $report->value)
                ->lockForUpdate()
                ->first();

            if ($layout === null) {
                return;
            }

            $layout->delete();

            activity()
                ->performedOn($layout)
                ->causedBy($actor)
                ->event('report_layout.updated')
                ->withProperties(['property_id' => $property->id, 'report_key' => $report->value, 'reset' => true])
                ->log('Report layout reset');
        });
    }
}
