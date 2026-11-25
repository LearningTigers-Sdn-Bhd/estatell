<?php

namespace App\Actions\Pos;

use App\Models\PosOutlet;

/**
 * The saved outlet, or a refusal code from `SavePosOutlet`.
 */
class PosOutletResult
{
    public const REFUSAL_CODE_INVALID = 'code_invalid';

    public const REFUSAL_CODE_TAKEN = 'code_taken';

    public const REFUSAL_CHARGE_ITEM_INVALID = 'charge_item_invalid';

    public const REFUSAL_EXCLUSIVE_TAX_NOT_ALLOWED = 'exclusive_tax_not_allowed';

    public const REFUSAL_NATIVE_POS_DISABLED = 'native_pos_disabled';

    private function __construct(public readonly ?PosOutlet $outlet, public readonly ?string $refusal) {}

    public static function saved(PosOutlet $outlet): self
    {
        return new self($outlet, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
