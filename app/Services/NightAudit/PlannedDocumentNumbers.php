<?php

namespace App\Services\NightAudit;

use App\Enums\DocumentType;
use Illuminate\Container\Attributes\Singleton;

/**
 * Document numbers that were issued elsewhere and must be kept, one queue for each document type. The wastays replay
 * pushes a number just before the Action that issues one document, and `NextDocumentNumber` takes it. The queues are
 * empty in the desk flow, so nothing changes there. See docs/migration-plan/grand-eastern-hotel.md "Document numbers".
 */
#[Singleton]
class PlannedDocumentNumbers
{
    /** @var array<string, list<int>> */
    private array $queues = [];

    /** @var array<string, int> */
    private array $ceilings = [];

    /**
     * Lets the replay issue a planned number of the type that is below the last number, as long as it is not above the
     * ceiling (the highest number wastays issued), and gives an unplanned number above the ceiling. Unique by
     * construction: wastays numbers are unique and the unplanned ones start above all of them.
     */
    public function setCeiling(DocumentType $type, ?int $ceiling): void
    {
        if ($ceiling === null) {
            unset($this->ceilings[$type->value]);

            return;
        }

        $this->ceilings[$type->value] = $ceiling;
    }

    public function ceiling(DocumentType $type): ?int
    {
        return $this->ceilings[$type->value] ?? null;
    }

    public function push(DocumentType $type, int $number): void
    {
        $this->queues[$type->value][] = $number;
    }

    /**
     * The next queued number of the type, or null when none is queued.
     */
    public function take(DocumentType $type): ?int
    {
        if (($this->queues[$type->value] ?? []) === []) {
            return null;
        }

        return array_shift($this->queues[$type->value]);
    }

    /**
     * The types that still have queued numbers, with the numbers.
     *
     * @return array<string, list<int>>
     */
    public function pending(): array
    {
        return array_filter($this->queues);
    }

    public function clear(): void
    {
        $this->queues = [];
    }
}
