<?php

namespace App\Data\Channels;

use App\Data\Platform\AdminOtaListRowData;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\PropertyOtaCredential;
use App\Models\User;
use Spatie\LaravelData\Data;

/**
 * The Connect page: the adapter fields and the OTA lists row and owner contact shown in the reference rail.
 */
class ChannelConnectPageData extends Data
{
    /**
     * @param  list<ChannelConnectFieldData>  $fields
     * @param  array<string, string|bool|int|null>  $values
     */
    public function __construct(
        public int $otaChannelId,
        public string $otaName,
        public array $fields,
        public array $values,
        public ?AdminOtaListRowData $otaList,
        public ?string $ownerName,
        public ?string $ownerEmail,
        public ?string $ownerPhone,
    ) {}

    /**
     * Expects the OTA lists row with `channel` loaded. The hotel ID starts from the row's property ID, as typed text only:
     * nothing written here goes back to OTA lists.
     *
     * @param  list<ChannelConnectFieldData>  $fields
     */
    public static function forProperty(Property $property, OtaChannel $ota, array $fields, ?PropertyOtaCredential $credential, ?User $owner): self
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field->name] = $field->default;
        }

        if ($credential !== null && array_key_exists('hotel_id', $values)) {
            $values['hotel_id'] = $credential->property_code;
        }

        return new self(
            otaChannelId: $ota->id,
            otaName: $ota->name,
            fields: $fields,
            values: $values,
            otaList: $credential === null ? null : AdminOtaListRowData::fromModel($credential),
            ownerName: $owner?->name,
            ownerEmail: $owner?->email,
            ownerPhone: $property->contact_phone,
        );
    }
}
