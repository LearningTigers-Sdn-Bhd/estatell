<?php

namespace App\Models;

use App\Enums\SignInResult;
use Carbon\CarbonImmutable;
use Database\Factories\SignInEventFactory;
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
 * One sign-in attempt on the web login. The IP address is stored encrypted and found by an exact-match blind index.
 * See docs/01-platform/07-sign-in-log.md.
 *
 * @property int $id
 * @property int|null $user_id
 * @property SignInResult $result
 * @property string $ip_address
 * @property string|null $country_code
 * @property string|null $os
 * @property string|null $browser
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['user_id', 'result', 'ip_address', 'country_code', 'os', 'browser', 'occurred_at'])]
class SignInEvent extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<SignInEventFactory> */
    use HasFactory, UsesCipherSweet;

    public const string IP_INDEX = 'ip_address_index';

    public const int RETENTION_DAYS = 90;

    public $timestamps = false;

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addTextField('ip_address')
            ->addBlindIndex('ip_address', new BlindIndex(self::IP_INDEX, [new Lowercase]));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => SignInResult::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
