<?php

namespace App\Actions\Onboarding\Wastays;

use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Models\Folio;
use App\Models\PaymentMethod;
use App\Models\ReservationUnit;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\WastaysBookingIndex;
use App\Services\Onboarding\Wastays\WastaysDocumentNumbers;
use App\Services\Onboarding\Wastays\WastaysImportTarget;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Brings the bookings of a wastays hotel across when its history stays in wastays. A stay that ended before go-live
 * becomes an Old stay (room and guest history, no money). A booking that arrives on or after go-live becomes a normal
 * booking, with what the guest or company paid in wastays as one Old system payment. OTA bookings come through Channex,
 * cancelled and voided bookings stay in wastays, and a stay still in the house is left for the hotel to decide. Bookings
 * go in by wastays reservation number, so the numbers stay in order, and each has an `import_links` row. See
 * docs/migration-plan/sand-bay-resort-semporna.md "Stays".
 */
class ImportWastaysStays
{
    public function __construct(
        private CreateWastaysBooking $createBooking,
        private RecordPayment $recordPayment,
        private FirstBusinessDate $firstBusinessDate,
        private ImportLinks $links,
    ) {}

    /**
     * A dry run does everything and rolls back.
     *
     * @throws InvalidArgumentException when the property has no first business date
     */
    public function __invoke(WastaysImportTarget $target, bool $dryRun = false): WastaysReplaySummary
    {
        $property = $target->property;
        $goLive = $this->firstBusinessDate->date($property) ?? throw new InvalidArgumentException('The property has no business date. Take it live first.');
        $summary = new WastaysReplaySummary;
        $context = new ReplayContext($property, $target->actor, $goLive->toDateString(), new WastaysBookingIndex($target->bundle), new WastaysDocumentNumbers($target->bundle), $summary);
        $timeZone = $property->settings->time_zone ?? config('app.timezone');

        DB::beginTransaction();

        try {
            foreach ($target->bundle->rows('bookings')->sortBy(fn (array $row): int => (int) $row['reservation_number']) as $booking) {
                $this->one($context, $booking, $goLive, (string) $timeZone);
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $summary;
    }

    /**
     * @param  array<string, string|null>  $booking
     */
    private function one(ReplayContext $context, array $booking, CarbonImmutable $goLive, string $timeZone): void
    {
        $id = (string) $booking['id'];
        $status = (string) $booking['status'];
        $arrival = $context->index->localDate((string) $booking['check_in']);
        $departure = $context->index->localDate((string) $booking['check_out']);
        $summary = $context->summary;

        if ($this->links->has($context->property, 'bookings', $id)) {
            $summary->count('already imported');

            return;
        }

        if (in_array($status, ['cancelled', 'voided', 'no_show_detected'], true)) {
            $summary->count('cancelled, voided or no-show bookings left in wastays');

            return;
        }

        $bucket = match (true) {
            in_array($status, ['checked_in', 'due_out_detected'], true) && $arrival < $goLive->toDateString() => 'migrated',
            $status === 'completed' && $departure < $goLive->toDateString() => 'old',
            $status === 'confirmed' && $arrival >= $goLive->toDateString() => $booking['source'] === 'ota' ? 'ota' : 'future',
            default => 'left',
        };

        if ($bucket === 'ota') {
            $summary->count('OTA bookings left to Channex');

            return;
        }

        if ($bucket === 'left') {
            $summary->count('stays in the house or past due, for the hotel to decide');

            return;
        }

        try {
            DB::transaction(function () use ($context, $id, $arrival, $departure, $bucket, $timeZone, $goLive): void {
                if ($bucket === 'migrated') {
                    // A guest still in the house leaves after the business date, so a departure already past moves to the next day.
                    $leaves = max($departure, $goLive->addDay()->toDateString());
                    $this->createBooking->migrated($context, $id, $arrival, $leaves, 'Imported from wastays', (string) BigDecimal::of($context->index->balance($id))->toScale(2), "wastays booking {$id}");
                    $context->summary->count('migrated stays in the house');

                    return;
                }

                if ($bucket === 'old') {
                    $this->createBooking->old($context, $id, $arrival, $departure, 'Imported from wastays');
                    $context->summary->count('old stays');

                    return;
                }

                $unit = $this->future($context, $id, $arrival, $departure);
                $this->payInOldSystem($context, $unit, $id, $timeZone);
                $context->summary->count('future bookings');
            });
        } catch (ReplayStopped $stopped) {
            $summary->count('bookings that could not be imported');
            $summary->note($stopped->getMessage());
        }
    }

    private function future(ReplayContext $context, string $id, string $arrival, string $departure): ReservationUnit
    {
        $nights = array_filter($context->index->nights($id), fn (string $price, string $date): bool => $date >= $arrival && $date < $departure, ARRAY_FILTER_USE_BOTH);
        $days = (int) CarbonImmutable::parse($arrival)->diffInDays(CarbonImmutable::parse($departure));
        $total = count($nights) === $days ? number_format(array_sum(array_map('floatval', $nights)), 2, '.', '') : null;

        return ($this->createBooking)($context, $id, $arrival, $departure, $total, 'wastays import', false);
    }

    /**
     * What the guest or company paid in wastays, net of refunds, as one payment from the old system.
     */
    private function payInOldSystem(ReplayContext $context, ReservationUnit $unit, string $id, string $timeZone): void
    {
        $paid = $context->index->paid($id, $timeZone);

        if ($paid['date'] === null || ! BigDecimal::of($paid['amount'])->isGreaterThan(0)) {
            return;
        }

        $method = PaymentMethod::query()->where('property_id', $context->property->id)->where('type', PaymentMethodType::OldSystem)->first()
            ?? throw new ReplayStopped("bookings {$id}: the property has no Old system payment method.");
        $references = array_values(array_filter(array_map(fn (string $line): ?string => $context->numbers->receiptReference('folio_transaction', $line), $paid['lines'])));
        $reference = $references === [] ? "wastays booking {$id}" : implode(', ', array_slice($references, 0, 3));
        $reservation = $unit->reservation;
        $master = Folio::query()->where('reservation_id', $reservation->id)->where('type', FolioType::Master)->orderBy('id')->first();
        $key = 'wastays:import-stays:'.$id;

        $result = ($this->recordPayment)($context->actor, $reservation, $master, $master === null ? $unit : null, new NewPaymentData(
            paymentMethodId: $method->id,
            purpose: PaymentPurpose::Advance,
            amount: (string) BigDecimal::of($paid['amount'])->toScale(2),
            reference: mb_substr($reference, 0, 100),
            idempotencyKey: $key,
            serviceDate: $paid['date'],
            migrated: true,
            lineKey: $key,
        ));

        if ($result->isRefused()) {
            throw new ReplayStopped("bookings {$id}: the payment from wastays was refused ({$result->refusal}).");
        }

        $context->summary->count('payments from the old system');
    }
}
