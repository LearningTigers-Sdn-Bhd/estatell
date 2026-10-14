<?php

namespace App\Actions\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportLayoutData;
use App\Data\Reports\ReportRow;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Enums\SpreadsheetFormat;
use App\Models\Property;
use App\Models\User;

class LogIdentityExport
{
    /**
     * Logs `report.exported_identity_numbers` when a PDF or spreadsheet holds full ID numbers: the report shows ID
     * numbers, the user has `guests.view-documents` (so the numbers are not masked), the ID or the ID number column is
     * visible in the layout and at least one exported row has a value in a visible one of them. Returns false and
     * writes nothing otherwise, so a masked export and a page view are never logged. The entry holds the report, the
     * dates, the row count and the format, never a number.
     *
     * @param  list<ReportRow>  $rows  The rows exported.
     * @param  SpreadsheetFormat|null  $format  Null for the PDF.
     */
    public function __invoke(User $actor, Property $property, ReportKey $report, ReportFilters $filters, ReportLayoutData $layout, array $rows, ?SpreadsheetFormat $format): bool
    {
        if (! $report->showsIdentityNumbers() || ! $actor->can(PermissionEnum::GuestsViewDocuments->value)) {
            return false;
        }

        $identityKeys = array_values(array_intersect(['identity', 'id_number'], $layout->visibleKeys()));

        if ($identityKeys === []) {
            return false;
        }

        if (collect($rows)->every(fn (ReportRow $row): bool => collect($identityKeys)->every(fn (string $key): bool => ($row->cells()[$key] ?? '') === ''))) {
            return false;
        }

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('report.exported_identity_numbers')
            ->withProperties([
                'property_id' => $property->id,
                'report_key' => $report->value,
                'from' => $filters->from,
                'to' => $filters->to,
                'rows' => count($rows),
                'format' => $format->value ?? 'pdf',
            ])
            ->log('Report exported with full ID numbers');

        return true;
    }
}
