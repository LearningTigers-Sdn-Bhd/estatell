<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Catalogues\CalculatedTax;
use App\Services\Catalogues\TaxCalculationResult;
use App\Services\Folio\FolioPostability;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Post a charge whose tax is already worked out (by `PosOrderCalculator`) to a guest folio, with every check `PostCharge`
 * makes: the unit is locked, the posting uses the business date, the unit is CheckedIn (or CheckedOut on its departure
 * date) and not blocked for room charges (`room_no_post`), and the folio is the stay's own and Open
 * (`folio_not_open`, `folio_settled_to_city_ledger`). The caller names the folio, because a POS order can split its lines
 * over several folios, and gives the tax lines exactly as calculated, so the folio never drifts a sen from the receipt.
 * `PostFolioCharge` writes the lines with quantity 1. The same key returns the lines already posted and writes nothing.
 * The routing rules that choose the folio, and the cumulative room charge limit, are the caller's job. A refusal writes
 * nothing. See docs/34-native-pos/04-tax-checkout-and-pms.md.
 */
class PostPrecalculatedCharge
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioPostability $folioPostability,
        private PostFolioCharge $postFolioCharge,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * @param  list<CalculatedTax>  $taxes
     */
    public function __invoke(?User $actor, ReservationUnit $unit, Folio $folio, TransactionCode $code, string $description, Money $net, array $taxes, string $key, Model $source): PostingResult
    {
        return DB::transaction(function () use ($actor, $unit, $folio, $code, $description, $net, $taxes, $key, $source): PostingResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $existing = FolioLine::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return $existing->folio->reservation_id === $locked->reservation_id
                    ? PostingResult::replayed([$existing, ...$existing->children()->orderBy('id')->get()->all()])
                    : PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $postable = $locked->status === UnitStatus::CheckedIn
                || ($locked->status === UnitStatus::CheckedOut && $locked->departure_date->toDateString() === $businessDate->toDateString());

            if (($actor !== null && $locked->property_id !== $actor->property_id && ! $actor->is_admin) || ! $postable) {
                return PostingResult::refused(PostingResult::REFUSAL_UNIT_NOT_POSTABLE);
            }

            if ($locked->no_post) {
                return PostingResult::refused(PostingResult::REFUSAL_ROOM_NO_POST);
            }

            $target = Folio::query()->lockForUpdate()->find($folio->id);

            if ($target === null || $target->property_id !== $locked->property_id || $target->reservation_id !== $locked->reservation_id || $code->property_id !== $locked->property_id || $code->kind !== TransactionKind::Charge) {
                return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
            }

            if ($target->status !== FolioStatus::Open) {
                return PostingResult::refused((string) $this->folioPostability->refusalFor($target));
            }

            $gross = array_reduce($taxes, fn (Money $total, CalculatedTax $tax): Money => $total->plus($tax->amount), $net);

            $lines = ($this->postFolioCharge)(
                $actor,
                $target,
                $code,
                $description,
                1,
                $net,
                TaxCalculationResult::calculated($net, $taxes, $gross),
                $key,
                $businessDate->toDateString(),
                $source,
            );

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('folio.charge_posted')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'folio_id' => $target->id,
                    'folio_line_id' => $lines[0]->id,
                    'transaction_code_id' => $code->id,
                    'amount' => $lines[0]->amount,
                    'tax_lines' => count($lines) - 1,
                    'posting_date' => $businessDate->toDateString(),
                    'source' => $source->getMorphClass().':'.$source->getKey(),
                ])
                ->log('Charge posted to folio');

            ($this->closeSettledUnitFolio)($actor, $target);

            return PostingResult::posted($lines);
        });
    }
}
