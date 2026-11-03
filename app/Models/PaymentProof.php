<?php

namespace App\Models;

use App\Enums\PaymentProofStatus;
use App\Services\Platform\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A payment proof an agent uploads in the portal for a booking. Staff accept it, which records the payment, or reject
 * it. The file is the `file` media item on the private media disk.
 *
 * @property int $id
 * @property int $property_id
 * @property int $reservation_id
 * @property int $uploaded_by
 * @property string $amount
 * @property string|null $reference
 * @property PaymentProofStatus $status
 * @property string|null $reject_reason
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $payment_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['amount', 'reference'])]
class PaymentProof extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const string FILE = 'file';

    /** @var list<string> */
    public const array MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentProofStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::FILE)
            ->singleFile()
            ->acceptsMimeTypes(self::MIME_TYPES)
            ->useDisk(app(PlatformSettings::class)->current()->media_disk->diskName());
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
