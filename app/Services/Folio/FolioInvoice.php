<?php

namespace App\Services\Folio;

use App\Models\Company;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\ReservationUnit;
use App\Services\CityLedger\CompanyBillTo;
use App\Services\Guests\CountryOptions;
use App\Services\Property\AmountInWords;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out the invoice of one Closed folio: the number and close date, the info strip with Bill to, the Rooms block,
 * the posted lines through the shared grouper (no Scheduled rows, the folio is closed), the payments and the balance. It
 * prints the folio's lines as they are now and stores nothing. The route refuses a folio that is not Closed.
 * See docs/13-folio/06-folio-invoice.md.
 */
class FolioInvoice
{
    public function __construct(
        private FolioDocumentRows $documentRows,
        private FolioPaymentRows $paymentRows,
        private DocumentLines $documentLines,
        private CompanyBillTo $companyBillTo,
        private CountryOptions $countryOptions,
        private AmountInWords $amountInWords,
    ) {}

    public function for(Folio $folio): FolioInvoiceDocument
    {
        $folio->loadMissing(['property.settings', 'property.printSetting', 'reservation.booker', 'reservation.company', 'payerGuest', 'payerCompany']);
        $property = $folio->property;
        $reservation = $folio->reservation;
        $settings = $property->printSetting;
        $timeZone = $property->settings->time_zone;
        $currency = $property->settings->currency;

        $units = $reservation->units()->with(['roomType', 'guests.guest'])->orderBy('id')->get();
        $places = $this->documentRows->places($property, $units);
        $lines = $folio->lines()->orderBy('posting_date')->orderBy('id')->get();
        $rows = $this->documentRows->for($property, $lines, $units, $places);
        $groups = $this->documentLines->group($rows, $settings);
        $onFolio = $this->unitsOnFolio($folio, $rows, $units);

        [$payments, $paid, $billed] = $this->paymentRows->for($property, collect([$folio->id => $folio]), $reservation->company?->name);
        $balance = (string) BigDecimal::of($groups->total)->minus($paid)->minus($billed)->toScale(2);

        return new FolioInvoiceDocument(
            invoiceNumber: (string) $folio->invoice_number,
            closedOn: ($folio->closed_at ?? now())->setTimezone($timeZone)->toImmutable(),
            currency: $currency,
            strip: $this->strip($folio, $onFolio),
            rooms: $settings->show_unit_on_lines ? [] : $this->documentRows->roomLines($onFolio, $places),
            lines: $groups,
            payments: $payments,
            paid: $paid,
            billed: $billed,
            balance: $balance,
            totalInWords: $settings->show_amount_in_words && $groups->groups !== [] ? $this->amountInWords->for($groups->total, $currency) : null,
            showSignatureLines: $settings->show_signature_lines,
        );
    }

    /**
     * The units that have a charge on the folio (a line that is reversed on it does not count), and the unit of a Unit folio.
     *
     * @param  list<DocumentRow>  $rows
     * @param  Collection<int, ReservationUnit>  $units
     * @return Collection<int, ReservationUnit>
     */
    private function unitsOnFolio(Folio $folio, array $rows, Collection $units): Collection
    {
        $reversed = array_filter(array_map(fn (DocumentRow $row): ?int => $row->reversalOfId, $rows));
        $ids = [$folio->reservation_unit_id];

        foreach ($rows as $row) {
            if ($row->reversalOfId === null && ! in_array($row->lineId, $reversed, true)) {
                $ids[] = $row->unitId;
            }
        }

        $ids = array_filter($ids);

        return $units->filter(fn (ReservationUnit $unit): bool => in_array($unit->id, $ids, true))->values();
    }

    /**
     * Bill to, Stay, Reservation and Your ref, the cells that have a value.
     *
     * @param  Collection<int, ReservationUnit>  $onFolio
     * @return list<array{label: string, lines: list<string>}>
     */
    private function strip(Folio $folio, Collection $onFolio): array
    {
        $reservation = $folio->reservation;
        $dated = $onFolio->isEmpty() ? $reservation->units : $onFolio;
        $from = $dated->isEmpty() ? null : CarbonImmutable::parse($dated->min(fn (ReservationUnit $unit): string => $unit->arrival_date->toDateString()));
        $to = $dated->isEmpty() ? null : CarbonImmutable::parse($dated->max(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString()));
        $stay = $from === null || $to === null ? '' : DateRange::long($from, $to).' · '.trans_choice(':count night|:count nights', (int) $from->diffInDays($to));
        $cells = [
            [__('Bill to'), $this->billTo($folio)],
            [__('Stay'), [$stay]],
            [__('Reservation'), [$reservation->reference]],
            [__('Your ref'), [trim((string) $reservation->external_reference)]],
        ];

        $strip = [];

        foreach ($cells as [$label, $lines]) {
            $lines = array_values(array_filter($lines, filled(...)));

            if ($lines !== []) {
                $strip[] = ['label' => $label, 'lines' => $lines];
            }
        }

        return $strip;
    }

    /**
     * The payer company (name, its numbers and billing address), else the payer guest (name and address), else the booker.
     * Only the parts that are set.
     *
     * @return list<string>
     */
    private function billTo(Folio $folio): array
    {
        if ($folio->payerCompany instanceof Company) {
            return [$folio->payerCompany->name, ...$this->companyBillTo->lines($folio->payerCompany)];
        }

        return $this->guestLines($folio->payerGuest ?? $folio->reservation->booker);
    }

    /**
     * @return list<string>
     */
    private function guestLines(Guest $guest): array
    {
        $address = array_values(array_filter([
            $guest->address_line1,
            $guest->address_line2,
            trim(($guest->postal_code ?? '').' '.($guest->city ?? '').' '.($guest->state ?? '')),
        ], filled(...)));

        return array_values(array_filter([
            $guest->full_name,
            ...$address,
            $address === [] ? null : $this->countryOptions->name($guest->country_code),
        ], filled(...)));
    }
}
