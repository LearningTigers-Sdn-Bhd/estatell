<?php

namespace App\Actions\Reservations;

use Carbon\CarbonInterface;

/**
 * What `AssignRoom` and `AssignBed` return: done, or a refusal code and the first night that is not free. The codes name the room or the bed.
 */
class AssignmentResult
{
    public const REFUSAL_UNIT_NOT_OPEN = 'unit_not_open';

    public const REFUSAL_ROOM_NOT_FOUND = 'room_not_found';

    public const REFUSAL_BED_NOT_FOUND = 'bed_not_found';

    public const REFUSAL_ROOM_NOT_FREE = 'room_not_free';

    public const REFUSAL_BED_NOT_FREE = 'bed_not_free';

    private function __construct(
        public readonly ?string $refusal,
        public readonly ?CarbonInterface $date,
    ) {}

    public static function assigned(): self
    {
        return new self(null, null);
    }

    public static function refused(string $refusal, ?CarbonInterface $date = null): self
    {
        return new self($refusal, $date);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
