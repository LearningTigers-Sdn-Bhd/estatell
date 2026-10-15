<?php

namespace App\Models;

use App\Enums\OtaCredentialStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyOtaCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The OTA extranet login that an owner hands over for one channel. Only the estatell team reads these, through the
 * admin page. The password is never sent to the browser.
 *
 * @property int $id
 * @property int $property_id
 * @property int $ota_channel_id
 * @property string|null $channel_name
 * @property string $property_code
 * @property string $username
 * @property string $password
 * @property string|null $market_manager_name
 * @property string|null $market_manager_phone
 * @property string|null $market_manager_email
 * @property OtaCredentialStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'ota_channel_id', 'channel_name', 'property_code', 'username', 'password', 'market_manager_name', 'market_manager_phone', 'market_manager_email', 'status'])]
class PropertyOtaCredential extends Model
{
    /** @use HasFactory<PropertyOtaCredentialFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $hidden = ['password'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'password' => 'encrypted',
            'status' => OtaCredentialStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<OtaChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(OtaChannel::class, 'ota_channel_id');
    }
}
