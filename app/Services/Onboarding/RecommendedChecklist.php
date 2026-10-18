<?php

namespace App\Services\Onboarding;

use App\Data\Onboarding\RecommendedChecklistData;
use App\Data\Onboarding\RecommendedItemData;
use App\Enums\OnboardingStep;
use App\Enums\PolicyChargeMode;
use App\Enums\StepState;
use App\Enums\SurchargeType;
use App\Enums\SystemTransactionCode;
use App\Models\OnboardingSkip;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\RecommendedItemDismissal;
use App\Models\RoomGroup;
use App\Models\RoomRevenuePolicy;

/**
 * The Recommended next checklist: what a property has not set up yet and the owner may still want. Reads only. A
 * "Not needed" answer hides an item (RecommendedItemDismissal). Returns null when no item is open.
 */
class RecommendedChecklist
{
    public const string LOGO = 'logo';

    public const string ROOM_REVENUE = 'room_revenue';

    public const string MISSED_ARRIVALS = 'missed_arrivals';

    public const string PAYMENT_SURCHARGES = 'payment_surcharges';

    public const string ROOM_GROUPS = 'room_groups';

    public const string SKIPPED_PREFIX = 'skipped:';

    /**
     * Whether a "Not needed" answer may be stored for this key. The logo is never dismissible. A skipped section is
     * dismissible when it is an optional step (Taxes is required, so its "we don't charge tax" skip is not an item).
     */
    public static function isDismissibleKey(string $item): bool
    {
        if (in_array($item, [self::ROOM_REVENUE, self::MISSED_ARRIVALS, self::PAYMENT_SURCHARGES, self::ROOM_GROUPS], true)) {
            return true;
        }

        if (! str_starts_with($item, self::SKIPPED_PREFIX)) {
            return false;
        }

        $step = OnboardingStep::tryFrom(substr($item, strlen(self::SKIPPED_PREFIX)));

        return $step !== null && ! $step->isRequired();
    }

    public function forProperty(Property $property): ?RecommendedChecklistData
    {
        $dismissed = RecommendedItemDismissal::query()
            ->where('property_id', $property->id)
            ->pluck('item')
            ->all();

        $items = array_values(array_filter(
            $this->items($property),
            fn (RecommendedItemData $item): bool => ! in_array($item->key, $dismissed, true),
        ));

        if (collect($items)->every(fn (RecommendedItemData $item): bool => $item->done)) {
            return null;
        }

        return new RecommendedChecklistData(
            done: count(array_filter($items, fn (RecommendedItemData $item): bool => $item->done)),
            total: count($items),
            items: $items,
        );
    }

    /**
     * Every item, in the fixed order of the doc, before dismissals are removed.
     *
     * @return list<RecommendedItemData>
     */
    private function items(Property $property): array
    {
        $items = [
            $this->logo($property),
            $this->roomRevenue($property),
            $this->missedArrivals($property),
        ];

        $skips = OnboardingSkip::query()
            ->where('property_id', $property->id)
            ->get()
            ->sortBy(fn (OnboardingSkip $skip): int => $skip->step->number());

        foreach ($skips as $skip) {
            if ($skip->step !== OnboardingStep::Taxes) {
                $items[] = $this->skippedSection($property, $skip->step);
            }
        }

        $items[] = $this->paymentSurcharges($property);
        $items[] = $this->roomGroups($property);

        return $items;
    }

    private function logo(Property $property): RecommendedItemData
    {
        $done = $property->getFirstMedia(Property::LOGO) !== null;

        return new RecommendedItemData(
            key: self::LOGO,
            label: __('Property logo'),
            summary: $done ? __('Shown on invoices and receipts') : __('No logo on invoices and receipts'),
            done: $done,
            actionLabel: __('Add'),
            actionUrl: route('settings.profile', $property),
            dismissible: false,
        );
    }

    private function roomRevenue(Property $property): RecommendedItemData
    {
        $done = RoomRevenuePolicy::query()
            ->where('property_id', $property->id)
            ->whereIn('code', [SystemTransactionCode::NoShowRevenue, SystemTransactionCode::CancellationRevenue])
            ->where('charge_mode', '!=', PolicyChargeMode::Free)
            ->exists();

        return new RecommendedItemData(
            key: self::ROOM_REVENUE,
            label: __('No-show and cancellation charges'),
            summary: $done ? __('Charged on some no-shows or cancellations') : __('All policies are Free'),
            done: $done,
            actionLabel: __('Set up'),
            actionUrl: route('settings.room-revenue.index', $property),
            dismissible: true,
        );
    }

    private function missedArrivals(Property $property): RecommendedItemData
    {
        return new RecommendedItemData(
            key: self::MISSED_ARRIVALS,
            label: __('Missed arrivals and overdue departures'),
            summary: __('Using the defaults'),
            done: false,
            actionLabel: __('Review'),
            actionUrl: route('settings.operations', $property),
            dismissible: true,
        );
    }

    private function skippedSection(Property $property, OnboardingStep $step): RecommendedItemData
    {
        $done = $step->check()->state($property) === StepState::Done;

        return new RecommendedItemData(
            key: self::SKIPPED_PREFIX.$step->value,
            label: __('Skipped: :section', ['section' => $step->label()]),
            summary: $done ? __('Set up') : __('Not set up yet'),
            done: $done,
            actionLabel: __('Open'),
            actionUrl: route($this->sectionRoute($step), $property),
            dismissible: true,
        );
    }

    private function paymentSurcharges(Property $property): RecommendedItemData
    {
        $done = PaymentMethod::query()
            ->where('property_id', $property->id)
            ->where('surcharge_type', '!=', SurchargeType::None)
            ->exists();

        return new RecommendedItemData(
            key: self::PAYMENT_SURCHARGES,
            label: __('Payment surcharges'),
            summary: $done ? __('A surcharge is set on a payment method') : __('No surcharge on any payment method'),
            done: $done,
            actionLabel: __('Set up'),
            actionUrl: route('settings.payment-methods.index', $property),
            dismissible: true,
        );
    }

    private function roomGroups(Property $property): RecommendedItemData
    {
        $names = RoomGroup::query()
            ->where('property_id', $property->id)
            ->orderBy('position')
            ->pluck('name')
            ->all();

        $done = $names !== [];

        return new RecommendedItemData(
            key: self::ROOM_GROUPS,
            label: __('Room groups'),
            summary: $done ? implode(', ', $names) : __('No room groups yet'),
            done: $done,
            actionLabel: __('Set up'),
            actionUrl: route('settings.rooms.index', $property),
            dismissible: true,
        );
    }

    /**
     * The settings page that holds the section, where the owner fills it in.
     */
    private function sectionRoute(OnboardingStep $step): string
    {
        return match ($step) {
            OnboardingStep::Basics => 'settings.profile',
            OnboardingStep::CheckInEssentials => 'settings.edit',
            OnboardingStep::Team => 'settings.staff.index',
            OnboardingStep::RoomTypes => 'settings.room-types.index',
            OnboardingStep::Rooms => 'settings.rooms.index',
            OnboardingStep::PaymentMethods => 'settings.payment-methods.index',
            OnboardingStep::Companies => 'finance.city-ledger.companies.index',
            OnboardingStep::BasicRate => 'settings.rate-plans.index',
            OnboardingStep::ChargeItems => 'settings.charge-items.index',
            OnboardingStep::Discounts => 'settings.discounts.index',
            OnboardingStep::ChannelManager => 'settings.ota-lists.edit',
            OnboardingStep::Taxes => 'settings.taxes.index',
            OnboardingStep::Review => 'settings.edit',
        };
    }
}
