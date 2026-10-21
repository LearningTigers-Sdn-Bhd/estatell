<?php

namespace App\Models;

use App\Enums\MailStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MailEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\Transformation\Lowercase;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * One mail send attempt. The first `to` address is stored encrypted and found by an exact-match blind index. See
 * docs/04-notifications/03-mail-log.md.
 *
 * @property int $id
 * @property int|null $property_id
 * @property string|null $notification_type
 * @property string $recipient
 * @property int $recipient_count
 * @property string $subject
 * @property MailStatus $status
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'notification_type', 'recipient', 'recipient_count', 'subject', 'status', 'provider_message_id', 'error', 'sent_at'])]
class MailEvent extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<MailEventFactory> */
    use HasFactory, UsesCipherSweet;

    public const string RECIPIENT_INDEX = 'recipient_index';

    public const int RETENTION_DAYS = 90;

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addTextField('recipient')
            ->addBlindIndex('recipient', new BlindIndex(self::RECIPIENT_INDEX, [new Lowercase]));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MailStatus::class,
            'sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
