<?php

namespace App\Actions\Pos;

use App\Enums\PermissionEnum;
use App\Enums\PosPinOutcome;
use App\Models\PosOutlet;
use App\Models\PosTerminalDevice;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The approver of a restricted POS action (void, discount, refund, reprint) from the request. A manager PIN typed inline
 * (`approver_id` + `approver_pin`) is verified with `VerifyPosApproval`. Without one, an actor who holds the permission
 * approves their own action unless the outlet requires dual control. `error` is the sentence staff read when the PIN fails,
 * and `approver` is null when nobody approved (the Action then asks for approval itself).
 */
class ResolvePosApproval
{
    public function __construct(private VerifyPosApproval $verifyPosApproval) {}

    /**
     * @param  array<string, int|string|null>  $context
     * @return array{approver: User|null, error: string|null}
     */
    public function __invoke(Request $request, Property $property, PosOutlet $outlet, User $actor, PermissionEnum $permission, string $action, array $context = []): array
    {
        $dual = $outlet->require_dual_control;
        $approverId = (int) $request->input('approver_id');
        $pin = (string) $request->input('approver_pin');

        if ($approverId > 0 && $pin !== '') {
            $device = $request->attributes->get('pos_device');

            if (! $device instanceof PosTerminalDevice) {
                return ['approver' => null, 'error' => __('Register this browser as a POS terminal to approve with a PIN.')];
            }

            $result = ($this->verifyPosApproval)($property, $device, $actor, $approverId, $pin, $permission, $dual, $action, $context);

            return $result->isVerified()
                ? ['approver' => $result->user, 'error' => null]
                : ['approver' => null, 'error' => $result->outcome === PosPinOutcome::Verified ? null : $result->outcome->label()];
        }

        if (! $dual && $actor->checkPermissionTo($permission->value)) {
            return ['approver' => $actor, 'error' => null];
        }

        return ['approver' => null, 'error' => null];
    }
}
