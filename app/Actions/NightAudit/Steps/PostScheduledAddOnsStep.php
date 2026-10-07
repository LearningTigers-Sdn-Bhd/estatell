<?php

namespace App\Actions\NightAudit\Steps;

use App\Actions\NightAudit\PostScheduledAddOns;
use App\Actions\NightAudit\RecordAuditException;
use App\Enums\AuditExceptionType;
use App\Enums\AuditStep;
use App\Models\NightAudit;
use App\Models\UnitAddOn;
use Carbon\CarbonImmutable;

class PostScheduledAddOnsStep implements AuditStepRunner
{
    public function __construct(
        private PostScheduledAddOns $postScheduledAddOns,
        private RecordAuditException $recordAuditException,
    ) {}

    /**
     * Post the add-ons whose service date is the business date through `PostScheduledAddOns`. Each refused add-on
     * becomes an exception row on its unit.
     */
    public function run(NightAudit $audit, CarbonImmutable $date): StepOutcome
    {
        $results = ($this->postScheduledAddOns)(null, $audit->property, $date);
        $refused = array_filter($results, fn ($result): bool => $result->isRefused());
        $units = UnitAddOn::query()->whereIn('id', array_keys($refused))->pluck('reservation_unit_id', 'id');

        foreach ($refused as $addOnId => $result) {
            ($this->recordAuditException)($audit, AuditStep::PostScheduledAddOns, AuditExceptionType::AddOn, (string) $result->refusal, $units[$addOnId] ?? null);
        }

        return new StepOutcome(count($results) - count($refused), count($refused));
    }
}
