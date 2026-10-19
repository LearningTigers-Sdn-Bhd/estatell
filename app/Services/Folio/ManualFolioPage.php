<?php

namespace App\Services\Folio;

use App\Actions\Folio\ManualBatchResult;
use App\Data\Catalogues\ChargeItemTaxData;
use App\Data\Folio\FolioOptionData;
use App\Data\Folio\FolioRailItemData;
use App\Data\Folio\ManualFolioOptionData;
use App\Data\Folio\ManualFolioPageData;
use App\Data\Folio\ManualPaymentOptionData;
use App\Data\Property\CurrencyData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\ManualFolioReason;
use App\Enums\PaymentStatus;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reservations\ReservationFormOptions;
use App\Services\Reservations\ReservationOverview;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reads the manual folio page: the lists its rows pick from (charge items with their own taxes, taxes, payment
 * methods, Open folios, postable rooms, refundable payments), the folio rail of the Open folios, an empty preview and
 * the Posted lines of the folio as the folio tab shows them, read only. It writes nothing. See docs/13-folio/08-manual-folio.md.
 */
class ManualFolioPage
{
    public function __construct(
        private ReservationFormOptions $options,
        private ReservationOverview $overview,
        private FolioStatement $statement,
        private ManualFolioPreview $preview,
        private MoveChargesPreview $labels,
        private ServiceDateRange $serviceDateRange,
        private CurrentBusinessDate $businessDate,
        private ManualLineMenu $lineMenu,
    ) {}

    /**
     * Expects the property's `settings` and the reservation's `booker` to be loaded and the folio to be Open.
     */
    public function build(User $user, Property $property, Reservation $reservation, Folio $folio, ?string $reason): ManualFolioPageData
    {
        $businessDate = $this->businessDate->date($property) ?? throw new InvalidArgumentException('The property has no open business date.');
        $unitLabels = $this->labels->unitLabels($reservation);
        $folios = $reservation->folios()->where('status', FolioStatus::Open)->with(['payerGuest', 'payerCompany:id,name'])->orderBy('id')->get();
        $units = $reservation->units()->whereIn('status', [UnitStatus::CheckedIn, UnitStatus::CheckedOut])->orderBy('id')->get();
        $overview = $this->overview->build($reservation, false);
        $label = $this->labels->folioLabel($folio, $unitLabels);
        $statement = $this->statement->build($property, $reservation, $overview['units'], (string) $folio->id, [], $user);
        $openKeys = $folios->map(fn (Folio $open): string => (string) $open->id)->all();

        return new ManualFolioPageData(
            reservationId: $reservation->id,
            reference: $reservation->reference,
            title: __('Manage folio manually · :reference', ['reference' => $reservation->reference]),
            folioId: $folio->id,
            folioLabel: $label,
            reason: $reason,
            reasonOptions: ManualFolioReason::options(),
            currency: CurrencyData::forProperty($property),
            businessDate: $businessDate->toDateString(),
            earliestDate: ($this->serviceDateRange->earliest($property, $reservation) ?? $businessDate)->toDateString(),
            chargeItems: $this->options->chargeItems($property),
            itemTaxIds: $this->itemTaxIds($property),
            taxes: $this->taxOptions($property),
            paymentMethods: $this->options->payments($property)->methods,
            payments: $this->payments($reservation),
            folios: $this->folioOptions($reservation),
            units: array_values($units->map(fn (ReservationUnit $unit): FolioOptionData => new FolioOptionData($unit->id, $unitLabels[$unit->id] ?? '—'))->all()),
            rail: array_values(array_filter($statement['folios'], fn (FolioRailItemData $item): bool => in_array($item->key, $openKeys, true))),
            preview: $this->preview->preview($property, $reservation, $folio, []),
            posted: $statement['folio'],
            lines: $statement['folio'] === null ? [] : $this->lineMenu->apply($user, $statement['folio'], $property->id),
            batchKey: (string) Str::uuid(),
            maxRows: ManualBatchResult::MAX_ROWS,
            cancelUrl: route('reservations.folio', ['property' => $property, 'reservation' => $reservation, 'folio' => $folio->id]),
        );
    }

    /**
     * The Open folios of the reservation, as the Split Dialog and the rows pick them.
     *
     * @return list<ManualFolioOptionData>
     */
    public function folioOptions(Reservation $reservation): array
    {
        $unitLabels = $this->labels->unitLabels($reservation);

        return array_values($reservation->folios()->where('status', FolioStatus::Open)->with(['payerGuest', 'payerCompany:id,name'])->orderBy('id')->get()->map(fn (Folio $open): ManualFolioOptionData => new ManualFolioOptionData(
            $open->id,
            $this->labels->folioLabel($open, $unitLabels),
            $open->type === FolioType::Unit ? $open->reservation_unit_id : null,
        ))->all());
    }

    /**
     * The property's taxes, by name.
     *
     * @return list<ChargeItemTaxData>
     */
    public function taxOptions(Property $property): array
    {
        return array_values($property->taxes()->orderBy('name')->get()->map(ChargeItemTaxData::fromModel(...))->all());
    }

    /**
     * Each active charge item's own tax ids, from the taxes that apply to its transaction code.
     *
     * @return array<int, list<int>>
     */
    private function itemTaxIds(Property $property): array
    {
        $byCode = $property->taxes()->with('applicableTransactionCodes:id')->get()
            ->flatMap(fn ($tax) => $tax->applicableTransactionCodes->map(fn ($code): array => [$code->id, $tax->id]))
            ->groupBy(0)
            ->map(fn ($pairs): array => $pairs->pluck(1)->sort()->values()->all());

        return ChargeItem::query()->where('property_id', $property->id)->active()->pluck('transaction_code_id', 'id')
            ->map(fn (int $codeId): array => $byCode->get($codeId, []))
            ->all();
    }

    /**
     * The completed payments of the reservation that still have money to give back.
     *
     * @return list<ManualPaymentOptionData>
     */
    private function payments(Reservation $reservation): array
    {
        return array_values(Payment::query()
            ->whereIn('folio_id', $reservation->folios()->select('folios.id'))
            ->whereNull('refund_of_id')
            ->where('status', PaymentStatus::Completed)
            ->withSum('refunds', 'amount')
            ->with('paymentMethod:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Payment $payment): ?ManualPaymentOptionData => BigDecimal::of($payment->amount)->minus((string) ($payment->refunds_sum_amount ?? '0'))->isPositive()
                ? new ManualPaymentOptionData(
                    $payment->id,
                    $payment->folio_id,
                    trim($payment->paymentMethod->name.' · '.$payment->amount.' · '.$payment->received_at->format('j M').($payment->receipt_number === null ? '' : ' · '.$payment->receipt_number)),
                    (string) BigDecimal::of($payment->amount)->minus((string) ($payment->refunds_sum_amount ?? '0'))->toScale(2),
                )
                : null)
            ->filter()
            ->all());
    }
}
