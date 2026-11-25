<?php

namespace App\Enums;

enum PosStockMovementKind: string
{
    case Sale = 'sale';
    case VoidReturn = 'void_return';
    case RefundReturn = 'refund_return';
    case PurchaseReceive = 'purchase_receive';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Waste = 'waste';
    case StocktakeAdjustment = 'stocktake_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Sale => __('Sold'),
            self::VoidReturn => __('Void returned'),
            self::RefundReturn => __('Refund returned'),
            self::PurchaseReceive => __('Received'),
            self::TransferIn => __('Transfer in'),
            self::TransferOut => __('Transfer out'),
            self::Waste => __('Waste'),
            self::StocktakeAdjustment => __('Stocktake adjustment'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
