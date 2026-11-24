<?php

namespace App\Actions\Pos;

use App\Enums\PermissionEnum;
use App\Enums\PosPinOutcome;
use App\Models\PosTerminalDevice;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\PosPinPolicy;

/**
 * Verify an inline Manager PIN override for one action (void, discount, refund, ...), inside the same request. The
 * approver's PIN is checked with the shared brute-force limits, then the approver must hold the permission the action
 * needs. Nothing is stored, so an approval can never be replayed for a second action. With dual control on, the approver
 * must be a different person from the cashier (`self_approval_not_allowed`). The approval is logged without the PIN.
 */
class VerifyPosApproval
{
    public function __construct(private VerifyPosPin $verifyPosPin, private PosPinPolicy $policy) {}

    /**
     * @param  string  $action  A short label for the log, e.g. `refund`.
     * @param  array<string, int|string|null>  $context  The order, line or amount the approval is for, for the log.
     */
    public function __invoke(Property $property, PosTerminalDevice $device, User $actor, int $approverId, string $pin, PermissionEnum $permission, bool $requireDualControl, string $action, array $context = []): PosPinResult
    {
        if ($requireDualControl && $approverId === $actor->id) {
            return PosPinResult::refused(PosPinOutcome::SelfApprovalNotAllowed);
        }

        $approver = User::query()->where('property_id', $property->id)->find($approverId);

        if ($approver === null) {
            return PosPinResult::refused(PosPinOutcome::InvalidPin);
        }

        $result = ($this->verifyPosPin)($property, $device, $approver, $pin);

        if (! $result->isVerified()) {
            return $result;
        }

        if (! $this->policy->withTeam($approver, fn (): bool => $approver->checkPermissionTo($permission->value))) {
            return PosPinResult::refused(PosPinOutcome::ApproverLacksPermission);
        }

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('pos.approval')
            ->withProperties(['action' => $action, 'permission' => $permission->value, 'approver_id' => $approver->id, 'device_id' => $device->id] + $context)
            ->log("POS {$action} approved");

        return $result;
    }
}
