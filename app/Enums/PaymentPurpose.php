<?php

namespace App\Enums;

/**
 * Why money was received. A refund copies the purpose of the payment it refunds. A deposit is a payment with the
 * Advance or SecurityDeposit purpose; there are no deposit tables. See docs/14-payments/01-payments-and-refunds.md.
 */
enum PaymentPurpose: string
{
    case Advance = 'advance';
    case Settlement = 'settlement';
    case SecurityDeposit = 'security_deposit';

    public function label(): string
    {
        return match ($this) {
            self::Advance => __('Advance'),
            self::Settlement => __('Settlement'),
            self::SecurityDeposit => __('Security deposit'),
        };
    }

    /**
     * The purposes a folio offers at a stage, the default first. A ThirdParty folio is always Settlement. A Unit folio
     * offers Advance before arrival, Settlement and Security deposit in-house, and Settlement after. A Master folio
     * offers Advance before arrival and Settlement after. The caller drops Advance from a unit whose Room charges go
     * to the Master folio.
     *
     * @return list<self>
     */
    public static function forStage(FolioType $type, PaymentStage $stage): array
    {
        return match (true) {
            $type === FolioType::ThirdParty => [self::Settlement],
            $stage === PaymentStage::BeforeArrival => [self::Advance],
            $stage === PaymentStage::InHouse && $type === FolioType::Unit => [self::Settlement, self::SecurityDeposit],
            default => [self::Settlement],
        };
    }

    /**
     * The purposes the Manual Folio Editor offers on a folio. The stay stage does not limit them, because staff enter
     * old payments there: a Unit folio takes all three, a Master folio Settlement and Advance, a Third party folio
     * Settlement only. The first is the default.
     *
     * @return list<self>
     */
    public static function forManualFolio(FolioType $type): array
    {
        return match ($type) {
            FolioType::Unit => [self::Settlement, self::Advance, self::SecurityDeposit],
            FolioType::Master => [self::Settlement, self::Advance],
            FolioType::ThirdParty => [self::Settlement],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $purpose): array => ['value' => $purpose->value, 'label' => $purpose->label()],
            self::cases(),
        );
    }
}
