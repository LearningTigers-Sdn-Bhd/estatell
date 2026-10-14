<?php

namespace App\Services\Platform;

use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * Reads the money corrections a support user made during one session, from the activity log: the entries the support
 * user caused for the impersonated staff user, on the property, since the session started. The owner's
 * `SupportAccessUsed` notice lists them. See docs/01-platform/04-impersonation.md#money-corrections.
 */
class SupportMoneyCorrections
{
    /**
     * Each money event, with the logged property that holds its reference (null when it has none).
     *
     * @var array<string, string|null>
     */
    private const array EVENT_REFERENCES = [
        'payment.refunded' => null,
        'ar_payment.voided' => 'receipt_number',
        'ar_invoice.voided' => 'number',
        'ar_invoice.credit_note_issued' => 'number',
        'ar_allocation.reversed' => null,
        'registration_card.voided' => null,
    ];

    /**
     * @return list<array{label: string, amount: ?string, reference: ?string}>
     */
    public function forSession(Property $property, User $supportUser, int $impersonatedUserId, CarbonImmutable $since): array
    {
        return array_values(Activity::query()
            ->causedBy($supportUser)
            ->whereIn('event', array_keys(self::EVENT_REFERENCES))
            ->where('created_at', '>=', $since)
            ->where('properties->property_id', $property->id)
            ->where('properties->impersonated_user_id', $impersonatedUserId)
            ->oldest('id')
            ->get()
            ->map(function (Activity $activity): array {
                $referenceKey = self::EVENT_REFERENCES[$activity->event];
                $amount = $activity->properties->get('amount');
                $reference = $referenceKey === null ? null : $activity->properties->get($referenceKey);

                return [
                    'label' => $this->label($activity->event),
                    'amount' => $amount === null ? null : (string) $amount,
                    'reference' => $reference === null ? null : (string) $reference,
                ];
            })
            ->values()
            ->all());
    }

    private function label(string $event): string
    {
        return match ($event) {
            'payment.refunded' => __('Refund'),
            'ar_payment.voided' => __('AR payment voided'),
            'ar_invoice.voided' => __('Invoice voided'),
            'ar_invoice.credit_note_issued' => __('Credit note issued'),
            'ar_allocation.reversed' => __('Allocation reversed'),
            'registration_card.voided' => __('Registration card voided'),
        };
    }
}
