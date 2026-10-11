<?php

namespace App\Data\Reservations;

use App\Data\Payments\PaymentMethodOptionData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * What the group check-out page shows: the rooms grouped as the Rows table of the doc, the Master line, the summary of
 * the choices and the payment methods. It is read only. `error` is set when no row can be built (no business date).
 */
class GroupCheckOutPreviewData extends Data
{
    /**
     * @param  list<GroupCheckOutRowData>  $rows
     * @param  list<PaymentMethodOptionData>  $methods
     */
    public function __construct(
        #[DataCollectionOf(GroupCheckOutRowData::class)]
        public array $rows,
        public ?GroupCheckOutMasterData $master,
        public GroupCheckOutSummaryData $summary,
        #[DataCollectionOf(PaymentMethodOptionData::class)]
        public array $methods,
        public ?string $error,
    ) {}
}
