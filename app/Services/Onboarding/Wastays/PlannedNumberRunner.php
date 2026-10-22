<?php

namespace App\Services\Onboarding\Wastays;

use App\Actions\Onboarding\Wastays\ReplayStopped;
use App\Enums\DocumentType;
use App\Services\NightAudit\PlannedDocumentNumbers;
use App\Services\NightAudit\PlannedNumberRefused;
use Closure;

/**
 * Queues a wastays document number, runs the Action that issues it and checks the number was used. A number the
 * sequence refuses becomes a stop of the replay. See docs/migration-plan/grand-eastern-hotel.md "Document numbers".
 */
final class PlannedNumberRunner
{
    public function __construct(private readonly PlannedDocumentNumbers $planned) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     *
     * @throws ReplayStopped
     */
    public function run(DocumentType $type, ?int $number, Closure $run, bool $mustUse, string $label)
    {
        $this->planned->clear();

        if ($number !== null) {
            $this->planned->push($type, $number);
        }

        try {
            $result = $run();
            $left = $this->planned->pending();
        } catch (PlannedNumberRefused $refused) {
            throw new ReplayStopped("{$label}: {$refused->getMessage()}");
        } finally {
            $this->planned->clear();
        }

        // A refused Action issues no number; the caller reports the refusal, which says more than an unused number.
        $refused = is_object($result) && method_exists($result, 'isRefused') && $result->isRefused();

        if ($mustUse && ! $refused && $left !== []) {
            throw new ReplayStopped("{$label}: the {$type->value} number {$number} was not used.");
        }

        return $result;
    }
}
