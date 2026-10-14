<?php

namespace App\Models;

use App\Enums\RegistrationCardStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RegistrationCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * The registration card of one guest on one unit. A signed card keeps a frozen `snapshot` and is never edited.
 *
 * @property int $id
 * @property int $property_id
 * @property int $unit_guest_id
 * @property string|null $number
 * @property RegistrationCardStatus $status
 * @property int|null $identity_document_id
 * @property int|null $terms_id
 * @property string|null $vehicle_plate
 * @property string|null $remarks
 * @property CarbonImmutable|null $signed_at
 * @property string|null $signer_name
 * @property string|null $void_reason
 * @property CarbonImmutable|null $voided_at
 * @property array<string, mixed>|null $snapshot
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['vehicle_plate', 'remarks'])]
class RegistrationCard extends Model implements HasMedia
{
    /** @use HasFactory<RegistrationCardFactory> */
    use HasFactory, InteractsWithMedia;

    public const SIGNATURE = 'signature';

    public const PDF = 'pdf';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RegistrationCardStatus::class,
            'signed_at' => 'datetime',
            'voided_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<UnitGuest, $this> */
    public function unitGuest(): BelongsTo
    {
        return $this->belongsTo(UnitGuest::class);
    }

    /** @return BelongsTo<RegistrationCardTerms, $this> */
    public function terms(): BelongsTo
    {
        return $this->belongsTo(RegistrationCardTerms::class, 'terms_id');
    }

    /** @return BelongsTo<GuestIdentityDocument, $this> */
    public function identityDocument(): BelongsTo
    {
        return $this->belongsTo(GuestIdentityDocument::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::SIGNATURE)->singleFile();
        $this->addMediaCollection(self::PDF)->singleFile();
    }
}
