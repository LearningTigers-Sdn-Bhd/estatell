<?php

namespace App\Actions\Pos;

use App\Enums\PosQrSessionKind;
use App\Models\PosQrSession;
use App\Models\UnitGuest;
use App\Services\Pos\PosPinHasher;
use App\Services\Pos\QrSessionState;
use Illuminate\Support\Facades\DB;

/**
 * Check the stay PIN and the primary guest's last name before a room QR shows its menu. Five wrong tries lock the token for
 * 15 minutes. The refusal does not say which of the two was wrong. A refusal writes the try count only.
 * See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class UnlockRoomQrSession
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    public function __construct(private PosPinHasher $hasher, private QrSessionState $state) {}

    public function __invoke(PosQrSession $session, string $pin, string $lastName): PosActionResult
    {
        return DB::transaction(function () use ($session, $pin, $lastName): PosActionResult {
            $session = PosQrSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if ($session === null || $session->kind !== PosQrSessionKind::Room) {
                return PosActionResult::refused('qr_invalid');
            }

            $state = ($this->state)($session);

            if ($state !== QrSessionState::ACTIVE) {
                return PosActionResult::refused('qr_'.$state);
            }

            $primary = UnitGuest::query()
                ->where('reservation_unit_id', $session->reservation_unit_id)
                ->where('is_primary', true)
                ->with('guest:id,full_name')
                ->first();

            $pinMatches = $session->stay_pin_hash !== null && $this->hasher->check($pin, $session->stay_pin_hash);
            $nameMatches = $primary !== null && $this->sameLastName($primary->guest->full_name, $lastName);

            if ($pinMatches && $nameMatches) {
                $session->forceFill(['failed_pin_attempts' => 0])->save();

                return PosActionResult::saved($session);
            }

            $attempts = $session->failed_pin_attempts + 1;
            $locked = $attempts >= self::MAX_ATTEMPTS;

            $session->forceFill([
                'failed_pin_attempts' => $locked ? 0 : $attempts,
                'locked_until' => $locked ? now()->addMinutes(self::LOCK_MINUTES) : null,
            ])->save();

            return PosActionResult::refused($locked ? 'qr_locked' : 'stay_verification_failed');
        });
    }

    private function sameLastName(string $fullName, string $lastName): bool
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $expected = mb_strtolower((string) end($parts));

        return $expected !== '' && $expected === mb_strtolower(trim($lastName));
    }
}
