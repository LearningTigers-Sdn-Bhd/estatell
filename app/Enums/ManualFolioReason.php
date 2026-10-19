<?php

namespace App\Enums;

/**
 * Why a folio is managed by hand, picked in the reason dialog. The case order is the select order. The batch keeps the
 * reason as text: the label, or the typed details for Other. See docs/13-folio/08-manual-folio.md.
 */
enum ManualFolioReason: string
{
    case PaperFolio = 'paper_folio';
    case MissedCharge = 'missed_charge';
    case MissedPayment = 'missed_payment';
    case OldSystem = 'old_system';
    case FixMistake = 'fix_mistake';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PaperFolio => __('Entering charges from the paper folio'),
            self::MissedCharge => __('A charge was missed'),
            self::MissedPayment => __('A payment was not recorded'),
            self::OldSystem => __('Moving from the old system'),
            self::FixMistake => __('Fixing a mistake'),
            self::Other => __('Other'),
        };
    }

    /**
     * The reason the batch keeps: the typed details for Other, else the label.
     */
    public function text(?string $details): string
    {
        return $this === self::Other ? trim((string) $details) : $this->label();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
