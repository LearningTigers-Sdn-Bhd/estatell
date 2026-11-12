<?php

namespace App\Services\EInvoice;

use App\Models\Property;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Asks LHDN whether a buyer's tax number belongs to their IC, passport or company registration number, and if not,
 * which tax number LHDN holds for that ID. Staff press Check with LHDN before sending, or Find the tax number on a
 * rejected e-invoice. It calls LHDN only when staff ask: LHDN throttles a client that checks before every submission
 * (about 60 calls a minute per Client ID), so a property gets 20 checks a minute. It writes nothing.
 * See docs/31-einvoice/01-lhdn-myinvois.md#checking-a-tax-number.
 */
class TaxNumberCheck
{
    public const string MATCH = 'match';

    public const string FOUND = 'found';

    public const string NOT_FOUND = 'not_found';

    public const string UNAVAILABLE = 'unavailable';

    public const string TOO_MANY = 'too_many';

    private const int CHECKS_PER_MINUTE = 20;

    /**
     * @return array{result: string, tin: string|null, message: string}
     */
    public function check(Property $property, ?string $tin, string $idType, string $idValue): array
    {
        $tin = strtoupper((string) preg_replace('/\s+/', '', (string) $tin));
        $idType = strtoupper($idType);
        $idValue = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $idValue));
        $setting = $property->eInvoiceSetting;

        if ($setting === null || ! MyInvoisClientFactory::isReady($setting)) {
            return $this->answer(self::UNAVAILABLE, null, $idType);
        }

        $limiterKey = "einvoice-tin-check:{$property->id}";
        if (RateLimiter::tooManyAttempts($limiterKey, self::CHECKS_PER_MINUTE)) {
            return $this->answer(self::TOO_MANY, null, $idType);
        }
        RateLimiter::hit($limiterKey, 60);

        return $this->ask(MyInvoisClientFactory::forSetting($setting), $tin, $idType, $idValue);
    }

    /**
     * @return array{result: string, tin: string|null, message: string}
     */
    private function ask(MyInvoisClient|MockMyInvoisClient $client, string $tin, string $idType, string $idValue): array
    {
        try {
            if ($tin !== '' && ! str_starts_with($tin, 'EI')) {
                try {
                    $client->validateTin($tin, $idType, $idValue);

                    return $this->answer(self::MATCH, $tin, $idType);
                } catch (MyInvoisApiException $exception) {
                    if ($exception->statusCode !== 404 && $exception->statusCode !== 400) {
                        throw $exception;
                    }
                }
            }

            $found = strtoupper((string) ($client->searchTin($idType, $idValue)['tin'] ?? ''));

            return $found === '' ? $this->answer(self::NOT_FOUND, null, $idType) : $this->answer($found === $tin ? self::MATCH : self::FOUND, $found, $idType);
        } catch (MyInvoisApiException $exception) {
            return $exception->statusCode === 404 || $exception->statusCode === 400
                ? $this->answer(self::NOT_FOUND, null, $idType)
                : $this->answer(self::UNAVAILABLE, null, $idType);
        }
    }

    /**
     * @return array{result: string, tin: string|null, message: string}
     */
    private function answer(string $result, ?string $tin, string $idType): array
    {
        $id = match ($idType) {
            'NRIC' => __('IC'),
            'PASSPORT' => __('passport'),
            'BRN' => __('registration number'),
            default => __('ID'),
        };

        return [
            'result' => $result,
            'tin' => $tin,
            'message' => match ($result) {
                self::MATCH => __('LHDN confirms this tax number belongs to this :id.', ['id' => $id]),
                self::FOUND => __('LHDN holds tax number :tin for this :id.', ['tin' => $tin, 'id' => $id]),
                self::NOT_FOUND => __('LHDN has no tax number for this :id. Check the :id number with the buyer.', ['id' => $id]),
                self::TOO_MANY => __('Too many checks this minute. Try again in a minute.'),
                default => __('LHDN could not be reached, so the tax number was not checked. You can still send the e-invoice.'),
            },
        ];
    }
}
