<?php

namespace App\Actions\NightAudit\Steps;

use App\Actions\Facilities\PostFacilityCharges;
use App\Actions\NightAudit\RecordAuditException;
use App\Enums\AuditExceptionType;
use App\Enums\AuditStep;
use App\Models\NightAudit;
use Carbon\CarbonImmutable;

class PostFacilityChargesStep implements AuditStepRunner
{
    public function __construct(
        private PostFacilityCharges $postFacilityCharges,
        private RecordAuditException $recordAuditException,
    ) {}

    /**
     * Post the facility bookings of the business date through `PostFacilityCharges`. Each refused booking (a Tentative
     * one, a closed folio) becomes a `Facility` exception linked to the booking, with no unit.
     */
    public function run(NightAudit $audit, CarbonImmutable $date): StepOutcome
    {
        $results = ($this->postFacilityCharges)($audit->property, $date);
        $refused = array_filter($results, fn ($result): bool => $result->isRefused());

        foreach ($refused as $bookingId => $result) {
            ($this->recordAuditException)($audit, AuditStep::PostFacilityCharges, AuditExceptionType::Facility, (string) $result->refusal, null, $bookingId);
        }

        return new StepOutcome(count($results) - count($refused), count($refused));
    }
}
