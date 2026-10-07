<?php

namespace App\Actions\Reports;

use App\Enums\PageOrientation;
use App\Enums\ReportKey;
use App\Models\Property;
use App\Models\ReportLayout;
use App\Models\User;
use App\Services\Reports\ReportLayoutResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateReportLayout
{
    public function __construct(private ReportLayoutResolver $resolver) {}

    /**
     * Saves the orientation and the full column list of a report for the property. The list must hold exactly the
     * columns of the report, each once, with at least one visible. The positions are the list order.
     *
     * @param  list<array{key: string, visible: bool}>  $columns  In their new order.
     *
     * @throws ValidationException
     */
    public function __invoke(User $actor, Property $property, ReportKey $report, PageOrientation $orientation, array $columns): void
    {
        $layoutData = $this->resolver->fromDraft($report, $orientation, $columns);

        DB::transaction(function () use ($actor, $property, $report, $layoutData): void {
            $layout = ReportLayout::query()
                ->where('property_id', $property->id)
                ->where('report_key', $report->value)
                ->lockForUpdate()
                ->first() ?? new ReportLayout(['report_key' => $report]);

            $layout->property_id = $property->id;
            $layout->orientation = $layoutData->orientation;
            $layout->save();

            $layout->columns()->delete();
            $layout->columns()->createMany(array_map(
                fn ($column, int $position): array => ['column_key' => $column->key, 'position' => $position, 'is_visible' => $column->visible],
                $layoutData->columns,
                array_keys($layoutData->columns),
            ));

            activity()
                ->performedOn($layout)
                ->causedBy($actor)
                ->event('report_layout.updated')
                ->withProperties(['property_id' => $property->id, 'report_key' => $report->value])
                ->log('Report layout updated');
        });
    }
}
