<?php

namespace App\Services\Platform;

use Spatie\Activitylog\Models\Activity;

/**
 * Reads a money correction from an activity log entry: a refund, void, credit note or allocation reversal. The owner's
 * `SupportAccessUsed` notice names it. See docs/01-platform/04-impersonation.md#money-corrections.
 */
class SupportMoneyCorrections
{
    /**
     * Each money event, with its label and the logged property that holds its reference (null when it has none).
     *
     * @var array<string, string|null>
     */
    private const array EVENT_REFERENCES = [
        'payment.refunded' => null,
        'payment.voided' => null,
        'ar_payment.voided' => 'receipt_number',
        'ar_invoice.voided' => 'number',
        'ar_invoice.credit_note_issued' => 'number',
        'ar_allocation.reversed' => null,
        'registration_card.voided' => null,
    ];

    /**
     * The correction an entry records, or null when the entry is not a money event.
     *
     * @return array{label: string, amount: ?string, reference: ?string}|null
     */
    public function fromActivity(Activity $activity): ?array
    {
        if ($activity->event === null || ! array_key_exists($activity->event, self::EVENT_REFERENCES)) {
            return null;
        }

        $referenceKey = self::EVENT_REFERENCES[$activity->event];
        $amount = $activity->properties->get('amount');
        $reference = $referenceKey === null ? null : $activity->properties->get($referenceKey);

        return [
            'label' => $this->label($activity->event),
            'amount' => $amount === null ? null : (string) $amount,
            'reference' => $reference === null ? null : (string) $reference,
        ];
    }

    private function label(string $event): string
    {
        return match ($event) {
            'payment.refunded' => __('Refund'),
            'payment.voided' => __('Payment voided'),
            'ar_payment.voided' => __('AR payment voided'),
            'ar_invoice.voided' => __('Invoice voided'),
            'ar_invoice.credit_note_issued' => __('Credit note issued'),
            'ar_allocation.reversed' => __('Allocation reversed'),
            default => __('Registration card voided'),
        };
    }
}
