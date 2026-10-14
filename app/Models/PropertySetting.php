<?php

namespace App\Models;

use App\Enums\Locale;
use App\Enums\MissedArrivalPolicy;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\RegistrationCardScope;
use App\Enums\WeekendNights;
use App\Models\Casts\AsCurrency;
use Brick\Money\Currency;
use Database\Factories\PropertySettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $property_id
 * @property Currency $currency
 * @property string $time_zone
 * @property Locale $default_locale
 * @property string $check_in_time
 * @property string $check_out_time
 * @property int $arrival_grace_minutes
 * @property bool $auto_assign_rooms
 * @property string $audit_time
 * @property bool $auto_audit_enabled
 * @property MissedArrivalPolicy $missed_arrival_policy
 * @property OverdueDeparturePolicy $overdue_departure_policy
 * @property RegistrationCardScope $registration_card_scope
 * @property bool $card_required_for_check_in
 * @property int|null $id_document_retention_days
 * @property string $security_deposit_amount
 * @property bool $allow_support_access
 * @property bool $allow_support_money_corrections
 * @property WeekendNights $weekend_nights
 */
#[Fillable([
    'currency', 'time_zone', 'default_locale', 'check_in_time', 'check_out_time',
    'arrival_grace_minutes', 'auto_assign_rooms', 'audit_time', 'auto_audit_enabled',
    'missed_arrival_policy', 'overdue_departure_policy',
    'registration_card_scope', 'card_required_for_check_in', 'id_document_retention_days',
    'security_deposit_amount', 'allow_support_access', 'allow_support_money_corrections', 'weekend_nights',
])]
class PropertySetting extends Model
{
    /** @use HasFactory<PropertySettingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'currency' => AsCurrency::class,
            'default_locale' => Locale::class,
            'arrival_grace_minutes' => 'integer',
            'auto_assign_rooms' => 'boolean',
            'auto_audit_enabled' => 'boolean',
            'missed_arrival_policy' => MissedArrivalPolicy::class,
            'overdue_departure_policy' => OverdueDeparturePolicy::class,
            'registration_card_scope' => RegistrationCardScope::class,
            'card_required_for_check_in' => 'boolean',
            'id_document_retention_days' => 'integer',
            'security_deposit_amount' => 'decimal:2',
            'allow_support_access' => 'boolean',
            'allow_support_money_corrections' => 'boolean',
            'weekend_nights' => WeekendNights::class,
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
