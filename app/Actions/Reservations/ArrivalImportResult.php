<?php

namespace App\Actions\Reservations;

use Carbon\CarbonInterface;

/**
 * The outcome of `ImportArrivals`: the counts, or the refusal code with the file line it belongs to.
 */
class ArrivalImportResult
{
    public const string REFUSAL_HAS_PROBLEMS = 'arrival_import_has_problems';

    public const string REFUSAL_NOTHING_TO_IMPORT = 'arrival_import_nothing_to_import';

    public const string REFUSAL_NO_OLD_SYSTEM_METHOD = 'arrival_import_no_old_system_method';

    private function __construct(
        public readonly int $reservations,
        public readonly int $units,
        public readonly int $deposits,
        public readonly ?string $refusal,
        public readonly ?int $line,
        public readonly ?CarbonInterface $date,
    ) {}

    public static function done(int $reservations, int $units, int $deposits): self
    {
        return new self($reservations, $units, $deposits, null, null, null);
    }

    public static function refused(string $refusal, ?int $line = null, ?CarbonInterface $date = null): self
    {
        return new self(0, 0, 0, $refusal, $line, $date);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
