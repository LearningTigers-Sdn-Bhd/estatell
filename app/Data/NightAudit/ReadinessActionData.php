<?php

namespace App\Data\NightAudit;

use App\Enums\AuditFix;
use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * A button on a sheet row. A `post` action runs a fix in place and may ask to be confirmed first. A `link` action
 * leaves the sheet for a page.
 */
class ReadinessActionData extends Data
{
    /**
     * @param  string  $kind  `post`, `link` or `dialog`. A `dialog` action opens a Dialog of the reservation page over the sheet, and `url` is where the Dialog loads its data.
     * @param  int|null  $unitId  The one unit a post action is limited to. Null means every unit of the row.
     * @param  string|null  $confirm  What to ask before a post action runs.
     */
    public function __construct(
        public string $kind,
        public string $label,
        public string $url,
        public ?int $unitId = null,
        public ?string $confirm = null,
        public ?string $dialog = null,
    ) {}

    public static function fix(AuditFix $fix, string $label, Property $property, int $reservationId, ?int $unitId = null, ?string $confirm = null): self
    {
        return new self(
            kind: 'post',
            label: $label,
            url: route('night-audit.fix', ['property' => $property->code, 'reservation' => $reservationId, 'fix' => $fix->value], absolute: false),
            unitId: $unitId,
            confirm: $confirm,
        );
    }

    /**
     * @param  string  $dialog  `check-in`, `check-in-all` or `check-out`.
     */
    public static function dialog(string $dialog, string $label, Property $property, int $reservationId, ?int $unitId = null): self
    {
        return new self(
            kind: 'dialog',
            label: $label,
            url: route('night-audit.reservation', ['property' => $property->code, 'reservation' => $reservationId], absolute: false),
            unitId: $unitId,
            dialog: $dialog,
        );
    }

    public static function link(string $label, string $url): self
    {
        return new self(kind: 'link', label: $label, url: $url);
    }
}
