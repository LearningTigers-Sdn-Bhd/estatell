<?php

namespace App\Services\Guests;

use App\Enums\GuestMatchReason;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * Finds guests of one property that may be the same person as the details given.
 * It only suggests. It never blocks anything, and it never returns merged or anonymized guests.
 */
class GuestMatcher
{
    /**
     * Longest suggestion list.
     */
    public const int LIMIT = 10;

    public function __construct(private GuestName $guestName, private IdentityNumber $identityNumber) {}

    /**
     * Strong matches come first, then more reasons, then the name. Blank details are skipped.
     *
     * @param  list<string>  $idNumbers  ID numbers to look for. Only used when `$includeIdNumbers` is true.
     * @param  bool  $includeIdNumbers  Only for staff who may view ID documents, so others cannot learn that a number exists.
     * @return list<array{guest: Guest, reasons: list<GuestMatchReason>}>
     */
    public function find(
        Property $property,
        ?string $name = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $dateOfBirth = null,
        array $idNumbers = [],
        bool $includeIdNumbers = false,
        ?int $excludeGuestId = null,
    ): array {
        $nameKey = filled($name) ? $this->guestName->key($name) : null;
        $email = filled($email) ? mb_strtolower(trim($email)) : null;
        $phone = $this->normalizePhone($phone, $property);
        $idNumbers = $includeIdNumbers ? $this->normalizeIdNumbers($idNumbers) : [];
        $idMatchedGuestIds = $idNumbers === [] ? [] : $this->guestIdsWithNumbers($idNumbers);

        if ($nameKey === null && $email === null && $phone === null && $idMatchedGuestIds === []) {
            return [];
        }

        $candidates = Guest::query()
            ->where('guests.property_id', $property->id)
            ->notMerged()
            ->whereNull('guests.anonymized_at')
            ->when($excludeGuestId !== null, fn (Builder $query) => $query->where('guests.id', '!=', $excludeGuestId))
            ->where(function (Builder $query) use ($nameKey, $email, $phone, $idMatchedGuestIds): void {
                $query->when($nameKey !== null, fn (Builder $query) => $query->orWhere('guests.name_key', $nameKey))
                    ->when($email !== null, fn (Builder $query) => $query->orWhereRaw('lower(guests.email) = ?', [$email]))
                    ->when($phone !== null, fn (Builder $query) => $query->orWhere('guests.phone', $phone))
                    ->when($idMatchedGuestIds !== [], fn (Builder $query) => $query->orWhereIn('guests.id', $idMatchedGuestIds));
            })
            ->get();

        $matches = [];

        foreach ($candidates as $guest) {
            $reasons = array_values(array_filter([
                $phone !== null && $guest->phone === $phone ? GuestMatchReason::Phone : null,
                $email !== null && $guest->email !== null && mb_strtolower(trim($guest->email)) === $email ? GuestMatchReason::Email : null,
                in_array($guest->id, $idMatchedGuestIds, true) ? GuestMatchReason::IdNumber : null,
                $this->nameReason($guest, $nameKey, $dateOfBirth),
            ]));

            if ($reasons !== []) {
                $matches[] = ['guest' => $guest, 'reasons' => $reasons];
            }
        }

        usort($matches, fn (array $a, array $b): int => [$this->strongCount($b['reasons']), count($b['reasons']), $a['guest']->full_name]
            <=> [$this->strongCount($a['reasons']), count($a['reasons']), $b['guest']->full_name]);

        return array_slice($matches, 0, self::LIMIT);
    }

    /**
     * Possible duplicates of an existing guest, from the guest's own details and, when allowed, ID numbers.
     *
     * @return list<array{guest: Guest, reasons: list<GuestMatchReason>}>
     */
    public function forGuest(Guest $guest, bool $includeIdNumbers = false): array
    {
        return $this->find(
            property: $guest->property,
            name: $guest->full_name,
            email: $guest->email,
            phone: $guest->phone,
            dateOfBirth: $guest->date_of_birth?->toDateString(),
            idNumbers: array_values($includeIdNumbers ? $guest->documents->map(fn (GuestIdentityDocument $document): string => $document->number)->all() : []),
            includeIdNumbers: $includeIdNumbers,
            excludeGuestId: $guest->id,
        );
    }

    private function nameReason(Guest $guest, ?string $nameKey, ?string $dateOfBirth): ?GuestMatchReason
    {
        if ($nameKey === null || $guest->name_key !== $nameKey) {
            return null;
        }

        return $dateOfBirth !== null && $guest->date_of_birth?->toDateString() === $dateOfBirth
            ? GuestMatchReason::NameAndBirthDate
            : GuestMatchReason::Name;
    }

    /**
     * @param  list<GuestMatchReason>  $reasons
     */
    private function strongCount(array $reasons): int
    {
        return count(array_filter($reasons, fn (GuestMatchReason $reason): bool => $reason->isStrong()));
    }

    /**
     * The phone number in E.164, read as a number of the property's country when it has no country code.
     * Null when it is blank or not a valid number.
     */
    public function normalizePhone(?string $phone, Property $property): ?string
    {
        if (blank($phone)) {
            return null;
        }

        try {
            return (new PhoneNumber($phone, $property->country_code ?? 'MY'))->formatE164();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $idNumbers
     * @return list<string>
     */
    private function normalizeIdNumbers(array $idNumbers): array
    {
        return array_values(array_filter(
            array_unique(array_map(fn (string $number): string => $this->identityNumber->normalize($number), $idNumbers)),
            fn (string $number): bool => strlen($number) >= 4,
        ));
    }

    /**
     * @param  list<string>  $idNumbers
     * @return list<int>
     */
    private function guestIdsWithNumbers(array $idNumbers): array
    {
        $guestIds = [];

        foreach ($idNumbers as $number) {
            $ids = GuestIdentityDocument::query()
                ->whereBlind('number', GuestIdentityDocument::NUMBER_INDEX, $number)
                ->pluck('guest_id')
                ->all();

            $guestIds = [...$guestIds, ...$ids];
        }

        return array_values(array_unique($guestIds));
    }
}
