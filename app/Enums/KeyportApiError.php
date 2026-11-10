<?php

namespace App\Enums;

use Illuminate\Http\JsonResponse;

/**
 * The error codes of the Keyport wire contract. The Actions return one instead of throwing; the controllers and
 * middleware turn it into `{"error":{"code":"...","message":"..."}}`.
 */
enum KeyportApiError: string
{
    case InvalidPairingCode = 'invalid_pairing_code';
    case PairingCodeExpired = 'pairing_code_expired';
    case PairingCodeUsed = 'pairing_code_used';
    case Unauthorized = 'unauthorized';
    case DoorLocksDisabled = 'door_locks_disabled';
    case UpgradeRequired = 'upgrade_required';
    case JobNotFound = 'job_not_found';
    case JobAlreadyFinished = 'job_already_finished';
    case InvalidRequest = 'invalid_request';

    public function status(): int
    {
        return match ($this) {
            self::InvalidPairingCode, self::JobNotFound => 404,
            self::PairingCodeExpired => 410,
            self::PairingCodeUsed, self::JobAlreadyFinished => 409,
            self::Unauthorized => 401,
            self::DoorLocksDisabled => 403,
            self::UpgradeRequired => 426,
            self::InvalidRequest => 422,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::InvalidPairingCode => __('This pairing code is not valid.'),
            self::PairingCodeExpired => __('This pairing code has expired. Make a new one in Estatell.'),
            self::PairingCodeUsed => __('This pairing code was already used. Make a new one in Estatell.'),
            self::Unauthorized => __('This computer is not paired, or was removed in Estatell.'),
            self::DoorLocksDisabled => __('Door locks are not turned on for this property.'),
            self::UpgradeRequired => __('This version of Keyport is too old. Update Keyport.'),
            self::JobNotFound => __('This job was not found for this computer.'),
            self::JobAlreadyFinished => __('This job already finished with a different result.'),
            self::InvalidRequest => __('The request is not valid.'),
        };
    }

    public function response(?string $message = null): JsonResponse
    {
        return response()->json(['error' => ['code' => $this->value, 'message' => $message ?? $this->message()]], $this->status());
    }
}
