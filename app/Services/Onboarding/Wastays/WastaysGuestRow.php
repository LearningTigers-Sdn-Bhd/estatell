<?php

namespace App\Services\Onboarding\Wastays;

use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\Gender;
use App\Enums\IdentityDocumentType;
use App\Services\Guests\CountryOptions;
use App\Services\Guests\IdentityNumber;
use Illuminate\Support\Facades\Validator;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * One row of a wastays `guests.csv`, read as estatell guest fields. It only reads and maps; the import Action writes.
 */
final class WastaysGuestRow
{
    /**
     * Alpha-3 codes of the countries a wastays passport is likely to name, as wastays may store alpha-3.
     *
     * @var array<string, string>
     */
    private const array ALPHA3 = [
        'MYS' => 'MY', 'SGP' => 'SG', 'IDN' => 'ID', 'THA' => 'TH', 'PHL' => 'PH', 'VNM' => 'VN', 'BRN' => 'BN', 'KHM' => 'KH',
        'LAO' => 'LA', 'MMR' => 'MM', 'CHN' => 'CN', 'HKG' => 'HK', 'TWN' => 'TW', 'JPN' => 'JP', 'KOR' => 'KR', 'IND' => 'IN',
        'PAK' => 'PK', 'BGD' => 'BD', 'LKA' => 'LK', 'NPL' => 'NP', 'AUS' => 'AU', 'NZL' => 'NZ', 'GBR' => 'GB', 'USA' => 'US',
        'CAN' => 'CA', 'FRA' => 'FR', 'DEU' => 'DE', 'NLD' => 'NL', 'ITA' => 'IT', 'ESP' => 'ES', 'RUS' => 'RU', 'ARE' => 'AE',
        'SAU' => 'SA', 'TUR' => 'TR',
    ];

    /** @var array<string, mixed> Profile fields for CreateGuest. */
    public array $attributes = [];

    public bool $emailDropped = false;

    /** The phone as written in wastays, when it cannot be read as a number. */
    public ?string $unreadablePhone = null;

    public int $unmatchedCountries = 0;

    public ?IdentityDocumentInputData $document = null;

    public bool $isVip = false;

    public bool $isBlacklisted = false;

    /**
     * @param  array<string, string|null>  $row
     */
    public function __construct(array $row, CountryOptions $countries, IdentityNumber $identityNumber)
    {
        $this->attributes = [
            'full_name' => mb_substr($this->text($row['name'] ?? null) ?? 'Unknown guest', 0, 150),
            'address_line1' => $this->limited($row['home_address'] ?? null, 150),
            'city' => $this->limited($row['city'] ?? null, 100),
            'postal_code' => $this->limited($row['postal_code'] ?? null, 20),
            'state' => $this->limited($row['state_code'] ?? null, 100),
            'date_of_birth' => $this->dateOfBirth($row['date_of_birth'] ?? null),
            'gender' => $this->gender($row['gender'] ?? null),
        ];

        $email = $this->text($row['email'] ?? null);
        if ($email !== null) {
            if (Validator::make(['email' => $email], ['email' => ['email:rfc', 'max:255']])->passes()) {
                $this->attributes['email'] = $email;
            } else {
                $this->emailDropped = true;
            }
        }

        $phone = $this->text($row['phone'] ?? null);
        if ($phone !== null) {
            $e164 = $this->phone($phone);
            if ($e164 !== null) {
                $this->attributes['phone'] = $e164;
            } else {
                $this->unreadablePhone = $phone;
            }
        }

        $names = [];
        foreach ($countries->options() as $option) {
            $names[mb_strtolower($option['label'])] = $option['value'];
        }
        foreach (['country' => 'nationality_code', 'address_country' => 'country_code'] as $column => $field) {
            $name = $this->text($row[$column] ?? null);
            if ($name === null) {
                continue;
            }
            $code = $names[mb_strtolower($name)] ?? null;
            if ($code === null) {
                $this->unmatchedCountries++;
            } else {
                $this->attributes[$field] = $code;
            }
        }

        $this->isVip = $this->isTrue($row['vip'] ?? null);
        $this->isBlacklisted = $this->isTrue($row['blacklisted'] ?? null);
        $this->document = $this->document($row, $identityNumber, array_values($names));
    }

    private function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }

    private function limited(?string $value, int $length): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private function isTrue(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['true', 't', '1'], true);
    }

    private function gender(?string $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'male', 'm' => Gender::Male->value,
            'female', 'f' => Gender::Female->value,
            default => null,
        };
    }

    private function dateOfBirth(?string $value): ?string
    {
        $value = $this->text($value);
        if ($value === null) {
            return null;
        }

        try {
            $date = now()->parse($value);
        } catch (Throwable) {
            return null;
        }

        return $date->isFuture() ? null : $date->format('Y-m-d');
    }

    private function phone(string $phone): ?string
    {
        try {
            $number = new PhoneNumber($phone, 'MY');

            return $number->isValid() ? $number->formatE164() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  list<string>  $countryCodes
     */
    private function document(array $row, IdentityNumber $identityNumber, array $countryCodes): ?IdentityDocumentInputData
    {
        $type = strtolower((string) $this->text($row['document_type'] ?? null));
        $governmentId = $this->text($row['government_id'] ?? null);
        $governmentNumber = $governmentId === null ? null : $identityNumber->normalize($governmentId);
        $governmentNumber = $governmentNumber === '' ? null : $governmentNumber;

        if ($type === 'passport') {
            $passport = $this->text($row['passport_number'] ?? null);
            $number = $identityNumber->normalize($passport ?? $governmentId ?? '');

            if ($number === '') {
                return null;
            }

            return new IdentityDocumentInputData(
                IdentityDocumentType::Passport,
                $number,
                $this->issuingCountry($this->text($row['passport_issuing_country_code'] ?? null), $countryCodes),
                null,
            );
        }

        if ($governmentNumber === null) {
            return null;
        }

        $isMyKad = preg_match('/^\d{12}$/', $governmentNumber) === 1;

        return new IdentityDocumentInputData(
            $isMyKad ? IdentityDocumentType::MyKad : IdentityDocumentType::Other,
            $governmentNumber,
            null,
            null,
        );
    }

    /**
     * @param  list<string>  $countryCodes
     */
    private function issuingCountry(?string $code, array $countryCodes): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = strtoupper($code);
        $code = self::ALPHA3[$code] ?? $code;

        return in_array($code, $countryCodes, true) ? $code : null;
    }
}
