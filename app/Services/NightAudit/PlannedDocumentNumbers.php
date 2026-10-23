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
