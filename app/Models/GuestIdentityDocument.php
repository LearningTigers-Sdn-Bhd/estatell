<?php

namespace App\Models;

use App\Enums\IdentityDocumentType;
use App\Services\Guests\IdentityNumber;
use App\Services\Platform\PlatformSettings;
use Carbon\CarbonImmutable;
use Database\Factories\GuestIdentityDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\Transformation\AlphaNumericCharactersOnly;
use ParagonIE\CipherSweet\Transformation\Lowercase;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A guest's ID document. The `number` is stored encrypted and found by an exact-match blind index.
 * The front and back scans are media files on the platform's media disk.
 *
 * @property int $id
 * @property int $guest_id
 * @property IdentityDocumentType $type
 * @property string $number
 * @property string|null $issuing_country_code
 * @property CarbonImmutable|null $expires_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['type', 'number', 'issuing_country_code', 'expires_on'])]
class GuestIdentityDocument extends Model implements CipherSweetEncrypted, HasMedia
{
    /** @use HasFactory<GuestIdentityDocumentFactory> */
    use HasFactory, InteractsWithMedia, UsesCipherSweet;

    public const string FRONT_SCAN = 'id_front';

    public const string BACK_SCAN = 'id_back';

    public const string NUMBER_INDEX = 'number_index';

    /**
     * Scan files can be 10 MB each. Images and PDFs only.
     */
    public const array SCAN_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addTextField('number')
            ->addBlindIndex('number', new BlindIndex(self::NUMBER_INDEX, [new AlphaNumericCharactersOnly, new Lowercase]));
    }

    /**
     * Each scan keeps one file. New files go to the disk chosen in platform settings; older files stay on their own disk.
     */
    public function registerMediaCollections(): void
    {
        $disk = app(PlatformSettings::class)->current()->media_disk->diskName();

        foreach ([self::FRONT_SCAN, self::BACK_SCAN] as $collection) {
            $this->addMediaCollection($collection)
                ->singleFile()
                ->acceptsMimeTypes(self::SCAN_MIME_TYPES)
                ->useDisk($disk);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IdentityDocumentType::class,
            'expires_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * The number as staff read it, for example a MyKad with its dashes.
     */
    public function formattedNumber(): string
    {
        return $this->type->formatNumber($this->number);
    }

    public function maskedNumber(): string
    {
        return app(IdentityNumber::class)->mask($this->number);
    }
}
