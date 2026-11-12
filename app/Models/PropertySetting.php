<?php

namespace App\Models;

use App\Enums\KioskKeyMode;
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
 * @property int $migration_window_days
 * @property int $agent_deposit_percent The share of the booking total an agent pays first.
 * @property int $agent_deposit_hours Hours after booking the deposit is due.
 * @property int $agent_full_payment_days Days before arrival the whole booking is due.
 * @property int|null $charter_charge_item_id The charge item a Charter boat trip adds once (Sand Bay: Charter Boat RM280). Null: nothing is added.
 * @property KioskKeyMode $kiosk_key_mode
 * @property bool $kiosk_walk_in_enabled
 * @property bool $concierge_walk_in_enabled
 * @property string|null $house_rules
 * @property bool $concierge_refund_requests_enabled
 * @property int $kiosk_idle_timeout_seconds
 * @property string|null $about
 * @property string|null $arrival_instructions
 * @property string|null $departure_instructions
 * @property bool $front_desk_open_24h
 * @property string|null $front_desk_opens_at
 * @property string|null $front_desk_closes_at
 * @property string|null $front_desk_whatsapp
 * @property string|null $after_hours_message
 * @property string|null $duty_manager_phone
 * @property string|null $emergency_phone
 * @property string|null $emergency_services_number
 * @property string|null $emergency_instructions
 */
#[Fillable([
    'currency', 'time_zone', 'default_locale', 'check_in_time', 'check_out_time',
    'arrival_grace_minutes', 'auto_assign_rooms', 'audit_time', 'auto_audit_enabled',
    'missed_arrival_policy', 'overdue_departure_policy',
    'registration_card_scope', 'card_required_for_check_in', 'id_document_retention_days',
    'security_deposit_amount', 'allow_support_access', 'allow_support_money_corrections', 'weekend_nights',
    'migration_window_days', 'charter_charge_item_id', 'agent_deposit_percent', 'agent_deposit_hours', 'agent_full_payment_days',
    'kiosk_key_mode', 'kiosk_walk_in_enabled', 'kiosk_idle_timeout_seconds', 'concierge_walk_in_enabled', 'house_rules', 'concierge_refund_requests_enabled',
    'about', 'arrival_instructions', 'departure_instructions', 'front_desk_open_24h', 'front_desk_opens_at', 'front_desk_closes_at',
    'front_desk_whatsapp', 'after_hours_message', 'duty_manager_phone', 'emergency_phone', 'emergency_services_number', 'emergency_instructions',
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
            'migration_window_days' => 'integer',
            'charter_charge_item_id' => 'integer',
            'agent_deposit_percent' => 'integer',
            'agent_deposit_hours' => 'integer',
            'agent_full_payment_days' => 'integer',
            'kiosk_key_mode' => KioskKeyMode::class,
            'kiosk_walk_in_enabled' => 'boolean',
            'concierge_walk_in_enabled' => 'boolean',
            'concierge_refund_requests_enabled' => 'boolean',
            'front_desk_open_24h' => 'boolean',
            'kiosk_idle_timeout_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
