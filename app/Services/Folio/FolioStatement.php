<?php

namespace App\Services\Folio;

use App\Data\Folio\FolioData;
use App\Data\Folio\FolioOptionData;
use App\Data\Folio\FolioRailItemData;
use App\Data\Folio\FolioRowData;
use App\Data\Folio\PostFolioOptionData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Data\Shared\BadgeData;
use App\Enums\ArInvoiceStatus;
use App\Enums\FolioRowTag;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PermissionEnum;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\ArInvoice;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\CityLedger\ArInvoiceVoidCheck;
use App\Services\CityLedger\CreditCheck;
use App\Services\Payments\PaymentPurposeChoices;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Reads the folio tab of a reservation: the rail (every folio that is not Voided, then a read-only entry for each
 * unit whose Scheduled rows have no folio yet) and the open entry with its Posted and Scheduled rows. Balance is the
 * posted lines only. Projected total adds the Scheduled rows. It writes nothing and creates no folio.
 * See docs/13-folio/05-folio-page.md.
 */
class FolioStatement
{
    public function __construct(
        private ScheduledFolioRows $scheduledRows,
        private PaymentPurposeChoices $paymentPurposeChoices,
        private FolioReopenRule $folioReopenRule,
        private CreditCheck $creditCheck,
        private LineSourceUnits $lineSourceUnits,
        private ArInvoiceVoidCheck $arInvoiceVoidCheck,
    ) {}

    /**
     * Expects the property's `settings` and the reservation's `booker` to be loaded. An unknown `$selected` falls back to the Master folio, or
     * the first entry.
     *
     * `$can` says what the user may do: post a charge, reverse a line, move charges, apply or remove a discount, close the folio, record or refund a payment, correct the price of a night. Rows and options carry only
     * what is allowed now, so the page never works it out.
     *
     * @param  list<ReservationUnitOptionData>  $units
     * @param  array{postCharge?: bool, reverse?: bool, transfer?: bool, discount?: bool, close?: bool, recordPayment?: bool, refund?: bool, printVoucher?: bool, settleCityLedger?: bool, changePrice?: bool}  $can
     * @return array{folios: list<FolioRailItemData>, folio: FolioData|null}
     */
    public function build(Property $property, Reservation $reservation, array $units, string $selected = '', array $can = [], ?User $user = null): array
    {
        $labels = collect($units)->mapWithKeys(fn (ReservationUnitOptionData $unit): array => [$unit->id => $unit->assigned ?? $unit->roomType])->all();
        $folios = $this->folios($reservation, array_keys($labels));
        $balances = $this->balances($folios);
        $blocks = $this->scheduledRows->forReservation($property, $reservation, $labels);

        $entries = $folios->map(fn (Folio $folio): array => $this->entry((string) $folio->id, $folio, null, new FolioRailItemData(
            (string) $folio->id,
            $folio->type->value,
            $this->name($folio, $labels),
            $folio->payerGuest->full_name ?? $folio->payerCompany?->name,
            BadgeData::fromFolioStatus($folio->status),
            $balances[$folio->id] ?? '0.00',
        )))->values();

        $notStarted = collect($blocks)->where('target', null)->pluck('unit')->unique()->values();

        foreach ($notStarted as $unitId) {
            $entries->push($this->entry('u'.$unitId, null, $unitId, new FolioRailItemData('u'.$unitId, 'not_started', __('Unit :unit', ['unit' => $labels[$unitId] ?? '—']), __('Not started'), null, null)));
        }

        $open = $entries->firstWhere('key', $selected)
            ?? $entries->first(fn (array $entry): bool => $entry['folio']?->type === FolioType::Master)
            ?? $entries->first();

        return [
            'folios' => array_values($entries->map(fn (array $entry): FolioRailItemData => $entry['item'])->all()),
            'folio' => $open === null ? null : $this->data($reservation, $open, $entries, $units, $blocks, $balances, $can, $labels, $user),
        ];
    }

    /**
     * @return array{key: string, folio: Folio|null, unit: int|null, item: FolioRailItemData}
     */
    private function entry(string $key, ?Folio $folio, ?int $unit, FolioRailItemData $item): array
    {
        return ['key' => $key, 'folio' => $folio, 'unit' => $unit, 'item' => $item];
    }

    /**
     * Master first, then Unit folios in unit order, then ThirdParty folios.
     *
     * @param  list<int>  $unitOrder
     * @return EloquentCollection<int, Folio>
     */
    private function folios(Reservation $reservation, array $unitOrder): Collection
    {
        return Folio::query()
            ->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->where('status', '!=', FolioStatus::Voided)
            ->with('payerGuest', 'payerCompany:id,name')
            ->get()
            ->sortBy(fn (Folio $folio): array => [
                match ($folio->type) {
                    FolioType::Master => 0,
                    FolioType::Unit => 1,
                    FolioType::ThirdParty => 2,
                },
                $folio->type === FolioType::Unit ? (int) array_search($folio->reservation_unit_id, $unitOrder, true) : 0,
                $folio->id,
            ])
            ->values();
    }

    /**
     * The room a third-party folio of a guest defaults to in Post charge: the one postable unit the guest is on. A
     * guest on several of them, or none, has no default.
     *
     * @param  Collection<int, array{key: string, folio: Folio|null, unit: int|null, item: FolioRailItemData}>  $entries
     * @param  list<int>  $unitIds  The units a charge can be posted for.
     * @return array<int, int> Unit id by payer guest id
     */
    private function payerUnits(Collection $entries, array $unitIds): array
    {
        $guestIds = $entries
            ->map(fn (array $entry): ?int => $entry['folio']?->type === FolioType::ThirdParty ? $entry['folio']->payer_guest_id : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($guestIds === []) {
            return [];
        }

        return UnitGuest::query()
            ->whereIn('guest_id', $guestIds)
            ->whereIn('reservation_unit_id', $unitIds)
            ->get(['guest_id', 'reservation_unit_id'])
            ->groupBy('guest_id')
            ->filter(fn (Collection $rows): bool => $rows->count() === 1)
            ->map(fn (Collection $rows): int => $rows->first()->reservation_unit_id)
            ->all();
    }

    /**
     * @param  EloquentCollection<int, Folio>  $folios
     * @return array<int, string> Balance by folio id
     */
    private function balances(Collection $folios): array
    {
        return FolioLine::query()
            ->whereIn('folio_id', $folios->modelKeys())
            ->groupBy('folio_id')
            ->selectRaw('folio_id, SUM(amount)::numeric(14, 2) AS balance')
            ->pluck('balance', 'folio_id')
            ->map(fn (mixed $balance): string => (string) $balance)
            ->all();
    }

    /**
     * The folio's name with its payer, as the Move charges page and the routing selects show it: "Third party · Blue Reef Dive".
     * Expects `payerGuest` and `payerCompany` to be loaded.
     *
     * @param  array<int, string>  $labels  Unit label by unit id
     */
    public function optionLabel(Folio $folio, array $labels): string
    {
        return trim($this->name($folio, $labels).' · '.($folio->payerGuest->full_name ?? $folio->payerCompany?->name), ' ·');
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function name(Folio $folio, array $labels): string
    {
        return match ($folio->type) {
            FolioType::Master => __('Master'),
            FolioType::Unit => __('Unit :unit', ['unit' => $labels[$folio->reservation_unit_id] ?? '—']),
            FolioType::ThirdParty => __('Third party'),
        };
    }

    /**
     * @param  array{key: string, folio: Folio|null, unit: int|null, item: FolioRailItemData}  $entry
     * @param  Collection<int, array{key: string, folio: Folio|null, unit: int|null, item: FolioRailItemData}>  $entries
     * @param  list<ReservationUnitOptionData>  $units
     * @param  list<array{target: int|null, unit: int, date: string, rows: list<FolioRowData>}>  $blocks
     * @param  array<int, string>  $balances
     * @param  array{postCharge?: bool, reverse?: bool, transfer?: bool, discount?: bool, close?: bool, recordPayment?: bool, refund?: bool, printVoucher?: bool, settleCityLedger?: bool, changePrice?: bool}  $can
     * @param  array<int, string>  $labels  Unit label by unit id
     */
    private function data(Reservation $reservation, array $entry, Collection $entries, array $units, array $blocks, array $balances, array $can, array $labels, ?User $user): FolioData
    {
        $folio = $entry['folio'];
        $isOpen = $folio?->status === FolioStatus::Open;
        $canMoveCharges = $isOpen && ($can['transfer'] ?? false) && $entries->contains(fn (array $other): bool => $other['folio'] !== null && $other['folio']->id !== $folio->id && $other['folio']->status === FolioStatus::Open);
        $postUnits = $isOpen && ($can['postCharge'] ?? false) ? collect($units)
            ->filter(fn (ReservationUnitOptionData $unit): bool => in_array($unit->status->value, [UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value], true))
            ->filter(fn (ReservationUnitOptionData $unit): bool => $folio->reservation_unit_id === null || $unit->id === $folio->reservation_unit_id)
            ->map(fn (ReservationUnitOptionData $unit): FolioOptionData => new FolioOptionData($unit->id, $unit->label))
            ->values()
            ->all() : [];
        $postRooms = $postUnits === [] ? [] : array_values(collect($units)
            ->filter(fn (ReservationUnitOptionData $unit): bool => in_array($unit->status->value, [UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value], true))
            ->map(fn (ReservationUnitOptionData $unit): FolioOptionData => new FolioOptionData($unit->id, $unit->label))
            ->all());
        $payerUnits = $postUnits === [] ? [] : $this->payerUnits($entries, array_map(fn (FolioOptionData $room): int => $room->id, $postRooms));
        $postFolios = $postUnits === [] ? [] : $entries
            ->filter(fn (array $other): bool => $other['folio'] !== null && $other['folio']->status === FolioStatus::Open)
            ->map(fn (array $other): PostFolioOptionData => new PostFolioOptionData(
                $other['folio']->id,
                trim($other['item']->name.' · '.$other['item']->subtitle, ' ·'),
                $other['folio']->type === FolioType::Unit ? $other['folio']->reservation_unit_id : null,
                $other['folio']->type === FolioType::ThirdParty ? ($payerUnits[$other['folio']->payer_guest_id] ?? null) : null,
            ))
            ->values()
            ->all();
        $withFolio = $entries->map(fn (array $other): ?int => $other['folio']?->reservation_unit_id)->filter()->all();
        $newFolios = $postUnits === [] ? [] : collect($postRooms)
            ->reject(fn (FolioOptionData $room): bool => in_array($room->id, $withFolio, true))
            ->map(fn (FolioOptionData $room): PostFolioOptionData => new PostFolioOptionData(null, __('Unit :unit · new folio', ['unit' => $room->label]), $room->id))
            ->values()
            ->all();
        $postFolios = [...$postFolios, ...$newFolios];
        $allowed = $isOpen ? array_values(array_filter([
            ($can['reverse'] ?? false) ? 'reverse' : null,
            $canMoveCharges ? 'move' : null,
        ])) : [];
        $scheduled = collect($blocks)
            ->filter(fn (array $block): bool => $folio === null ? ($block['target'] === null && $block['unit'] === $entry['unit']) : $block['target'] === $folio->id)
            ->flatMap(fn (array $block): array => $block['rows'])
            ->values();
        if ($can['printVoucher'] ?? false) {
            $scheduled->each(fn (FolioRowData $row) => $row->printable = true);
        }

        $priceUnitIds = $isOpen && ($can['changePrice'] ?? false) ? array_values(collect($units)
            ->filter(fn (ReservationUnitOptionData $unit): bool => in_array($unit->status->value, [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value], true))
            ->map(fn (ReservationUnitOptionData $unit): int => $unit->id)
            ->all()) : [];
        [$posted, $discountable] = $folio === null ? [collect(), false] : $this->postedRows($folio, $allowed, $isOpen && ($can['discount'] ?? false), $isOpen && ($can['refund'] ?? false), $can['recordPayment'] ?? false, $can['printVoucher'] ?? false, $labels, $priceUnitIds);
        $balance = $folio === null ? null : ($balances[$folio->id] ?? '0.00');
        $purposes = ($can['recordPayment'] ?? false) && ($folio === null || $isOpen)
            ? $this->paymentPurposeChoices->for($reservation, $folio, $entry['unit'])
            : [];
        $canSettle = ($can['settleCityLedger'] ?? false) && $isOpen && $folio->payer_company_id !== null && BigDecimal::of($balance)->isPositive();
        $billedBy = $folio !== null && $user !== null ? $this->folioReopenRule->billingInvoice($folio) : null;
        $invoiceVoided = $folio?->invoice_number !== null && $billedBy === null && ArInvoice::query()->where('folio_id', $folio->id)->where('status', ArInvoiceStatus::Void)->exists();
        $canVoidAndReopen = $billedBy !== null && $user->can(PermissionEnum::CityLedgerInvoice->value) && $user->can(PermissionEnum::FolioReopen->value);
        $projected = $scheduled->reduce(fn (BigDecimal $sum, FolioRowData $row): BigDecimal => $sum->plus($row->amount), BigDecimal::of($balance ?? '0'));

        return new FolioData(
            key: $entry['key'],
            kind: $entry['item']->kind,
            name: $entry['item']->name,
            status: $entry['item']->status,
            number: $folio?->number,
            invoiceNumber: $folio?->invoice_number,
            invoiceVoided: $invoiceVoided,
            balance: $balance,
            projectedTotal: (string) $projected->toScale(2),
            lineCount: $posted->count(),
            rows: array_values([...$posted->all(), ...$scheduled->all()]),
            canClose: $isOpen && ($can['close'] ?? false),
            canReopen: $folio?->status === FolioStatus::Closed && $billedBy === null && $user !== null && $this->folioReopenRule->allows($user, $folio),
            canApplyDiscount: $isOpen && ($can['discount'] ?? false) && $discountable,
            canPostCharge: $postUnits !== [],
            postUnits: array_values($postUnits),
            postFolios: $postFolios,
            postRooms: $postRooms,
            canMoveCharges: $canMoveCharges,
            canRecordPayment: $purposes !== [],
            canPrintVoucher: $posted->contains(fn (FolioRowData $row): bool => $row->printable) || $scheduled->contains(fn (FolioRowData $row): bool => $row->printable),
            paymentUnitId: $folio === null ? $entry['unit'] : null,
            canSettleToCityLedger: $canSettle,
            payerCompanyName: $folio?->payerCompany?->name,
            paymentPurposes: array_map(fn (PaymentPurpose $purpose): array => ['value' => $purpose->value, 'label' => $purpose->label()], $purposes),
            defaultPaymentPurpose: ($purposes[0] ?? null)?->value,
            settleCreditWarning: $canSettle ? $this->settleCreditWarning($folio) : null,
            canPrintInvoice: $folio?->status === FolioStatus::Closed && $folio->invoice_number !== null && ($can['printVoucher'] ?? false),
            arInvoiceId: $billedBy?->id,
            canOpenArInvoice: $billedBy !== null && $user->can(PermissionEnum::CompanyView->value),
            canCreditArInvoice: $billedBy !== null && $user->can(PermissionEnum::CityLedgerCreditNote->value),
            arInvoiceNumber: $billedBy?->number,
            canVoidAndReopen: $canVoidAndReopen,
            voidInvoiceBlockedBy: $canVoidAndReopen ? $this->arInvoiceVoidCheck->blockedBy($billedBy) : null,
            closedNotice: $folio !== null && $user !== null ? $this->folioReopenRule->notice($user, $folio) : null,
        );
    }

    /**
     * The warning of the Settle confirmation: the company is over its credit limit. Settle is never refused by the
     * limit (it moves an open folio balance into open AR), so this only warns. Null under the limit.
     */
    private function settleCreditWarning(Folio $folio): ?string
    {
        $company = Company::query()->where('property_id', $folio->property_id)->find($folio->payer_company_id);

        if ($company === null) {
            return null;
        }

        $check = $this->creditCheck->check($company);

        if ($check->allowed) {
            return null;
        }

        $currency = $folio->loadMissing('property.settings')->property->settings?->currency->getCurrencyCode();

        return __(':company is over its credit limit by :amount. The invoice is still issued.', [
            'company' => $company->name,
            'amount' => trim($currency.' '.number_format((float) $check->overBy, 2)),
        ]);
    }

    /**
     * The folio's lines in ledger order: each charge or payment, then its children (a discount, then its tax).
     *
     * A charge that is not a child, a reversal or already reversed takes the allowed actions. A discount line that
     * is not reversed offers Remove discount. A payment row carries its reference and the amount left to refund, and
     * offers Refund when something is left, and a receipt to a user with `payment.record`. A refund row is tagged Refund. The second value says whether any charge
     * can still take a discount (a live charge with no live discount line). A live room line of a night of a unit in
     * `$priceUnitIds` offers Correct price.
     *
     * @param  list<string>  $allowed
     * @param  array<int, string>  $labels  Unit label by unit id
     * @param  list<int>  $priceUnitIds
     * @return array{0: Collection<int, FolioRowData>, 1: bool}
     */
    private function postedRows(Folio $folio, array $allowed, bool $canRemoveDiscount, bool $canRefund, bool $canDownloadReceipt, bool $canPrintVoucher, array $labels, array $priceUnitIds = []): array
    {
        $nightMorph = (new UnitNight)->getMorphClass();
        $lines = $folio->lines()->orderBy('service_date')->orderBy('id')->get();
        $payments = $folio->payments()->withSum('refunds', 'amount')->get()->keyBy('folio_line_id');
        $reversed = $lines->pluck('reversal_of_id')->filter()->flip();
        $children = $lines->whereNotNull('parent_line_id')->groupBy('parent_line_id');
        $rows = collect();
        $sourceUnits = $this->lineSourceUnits->for($lines);

        $walk = function (FolioLine $line, int $level, ?int $parentUnitId) use (&$walk, $sourceUnits, $labels, $children, $reversed, $rows, $allowed, $canRemoveDiscount, $payments, $canRefund, $canDownloadReceipt, $canPrintVoucher, $nightMorph, $priceUnitIds): void {
            $tags = [];
            $unitId = $line->source_id === null ? $parentUnitId : ($sourceUnits[$line->source_type.':'.$line->source_id] ?? $parentUnitId);

            if ($line->reversal_of_id !== null) {
                $tags[] = FolioRowTag::Reversal;
            }

            if ($reversed->has($line->id)) {
                $tags[] = FolioRowTag::Reversed;
            }

            $live = $tags === [];
            $actions = match (true) {
                $level === 0 && $live && $line->kind === TransactionKind::Charge => $allowed,
                $level === 1 && $live && $line->kind === TransactionKind::Adjustment && $canRemoveDiscount => ['remove_discount'],
                default => [],
            };
            $correctsNight = $level === 0 && $live && $line->kind === TransactionKind::Charge && $line->source_type === $nightMorph
                && ! str_contains((string) $line->idempotency_key, ':incl:') && in_array($unitId, $priceUnitIds, true);

            if ($correctsNight) {
                $actions = [...$actions, 'correct_price'];
            }

            $payment = $payments->get($line->id);
            $refundable = null;

            if ($payment !== null && $payment->refund_of_id !== null) {
                $tags[] = FolioRowTag::Refund;
            } elseif ($payment !== null) {
                $refundable = (string) BigDecimal::of($payment->amount)->minus((string) ($payment->refunds_sum_amount ?? '0'))->toScale(2);
                $actions = $canRefund && $payment->status === PaymentStatus::Completed && BigDecimal::of($refundable)->isPositive() ? ['refund'] : [];
            }

            $rows->push(new FolioRowData('line:'.$line->id, 'posted', $line->service_date->toDateString(), $line->description, min($level, 2), $unitId === null ? null : ($labels[$unitId] ?? null), (string) $line->amount, array_map(BadgeData::fromFolioRowTag(...), $tags), $line->id, $actions, $payment?->reference, $payment?->id, $refundable, $canDownloadReceipt && $payment !== null && $payment->refund_of_id === null, $line->kind, $canPrintVoucher && FolioVoucher::isPrintable($line, $reversed->has($line->id)), $correctsNight ? $unitId : null, $correctsNight ? $line->source_id : null, $line->service_date->lt($line->posting_date) ? $line->posting_date->toDateString() : null));

            foreach ($children->get($line->id, []) as $child) {
                $walk($child, $level + 1, $unitId);
            }
        };

        $ids = $lines->pluck('id')->flip();

        foreach ($lines as $line) {
            if ($line->parent_line_id === null || ! $ids->has($line->parent_line_id)) {
                $walk($line, 0, null);
            }
        }

        $discounted = $lines
            ->filter(fn (FolioLine $line): bool => $line->kind === TransactionKind::Adjustment && $line->parent_line_id !== null && $line->reversal_of_id === null && ! $reversed->has($line->id))
            ->pluck('parent_line_id')
            ->flip();
        $discountable = $lines->contains(fn (FolioLine $line): bool => $line->kind === TransactionKind::Charge
            && $line->parent_line_id === null
            && $line->reversal_of_id === null
            && ! $reversed->has($line->id)
            && ! $discounted->has($line->id));

        return [$rows, $discountable];
    }
}
