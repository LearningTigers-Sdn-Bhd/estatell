<?php

namespace App\Data\Reservations;

use App\Data\Payments\PaymentMethodOptionData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * What the group check-out page shows: the rooms in the sections of the Rows table of the doc, the Master line, the summary of
 * the choices and the payment methods. It is read only. `error` is set when no row can be built (no business date).
 * `openFolios` lists the Open Master and Third party folios that still hold money, and is filled only when no room is
 * left (every room is out), so the page can offer to settle them.
 */
class GroupCheckOutPreviewData extends Data
{
    /**
     * @param  list<GroupCheckOutRowData>  $rows
     * @param  list<string>  $checkedOutNothing  The rooms already checked out with nothing to settle: not rows.
     * @param  list<string>  $cancelledNothing  The cancelled or no-show rooms with nothing to settle: not rows.
     * @param  list<PaymentMethodOptionData>  $methods
     */
    public function __construct(
        #[DataCollectionOf(GroupCheckOutRowData::class)]
        public array $rows,
        public array $checkedOutNothing,
        public array $cancelledNothing,
        public ?GroupCheckOutMasterData $master,
        public GroupCheckOutSummaryData $summary,
        #[DataCollectionOf(PaymentMethodOptionData::class)]
        public array $methods,
        public ?string $error,
        public ?UnitAuditPendingData $auditPending = null,
        /** @var list<GroupCheckOutFolioData> */
        #[DataCollectionOf(GroupCheckOutFolioData::class)]
        public array $openFolios = [],
    ) {}
}
