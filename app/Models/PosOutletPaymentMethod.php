<?php

namespace App\Models;

use App\Enums\PosTenderType;
use Carbon\CarbonImmutable;
use Database\Factories\PosOutletPaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tender an outlet accepts at the till, mapped to one of the property's payment methods for the journal.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property int $payment_method_id
 * @property PosTenderType $tender_type
 * @property bool $pulse_drawer
 * @property bool $active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['payment_method_id', 'tender_type', 'pulse_drawer', 'active', 'sort_order'])]
class PosOutletPaymentMethod extends Model
{
    /** @use HasFactory<PosOutletPaymentMethodFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tender_type' => PosTenderType::class,
            'pulse_drawer' => 'boolean',
            'active' => 'boolean',
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
     * @return BelongsTo<PosOutlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
