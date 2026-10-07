<?php

namespace App\Actions\FrontDesk;

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostFolioCharge;
use App\Actions\Folio\PostingResult;
use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\User;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Folio\FolioRouter;
use App\Services\Reservations\UnitNationality;
use Brick\Money\Money;

class PostUnitPolicyFee
{
    public function __construct(
        private FolioRouter $folioRouter,
        private CreateFolio $createFolio,
        private PostFolioCharge $postFolioCharge,
        private TaxCalculator $taxCalculator,
        private UnitNationality $unitNationality,
    ) {}

    /**
     * Post the fee of a cancellation or no-show policy on the unit, then one Tax child line for each tax component.
     * `PostCharge` cannot do this, because it refuses a unit that is not CheckedIn or CheckedOut. The caller holds
     * the unit row lock and runs this inside its transaction, before any other write. Every check runs before the
     * first write, so a refusal writes nothing. The folio comes from `FolioRouter`, and a unit with no folio gets its
     * own (`CreateFolio`). The key is `{$keyPrefix}:{unit id}:{n}`, where n counts the fees of that kind the unit
     * already has, so a unit cancelled again after a reinstate is charged again. A zero fee posts nothing.
     * See docs/18-front-desk/01-check-in-and-check-out.md and docs/13-folio/02-posting-charges.md.
     */
    public function __invoke(?User $actor, ReservationUnit $unit, RoomRevenuePolicy $policy, Money $fee, string $description, string $keyPrefix, string $postingDate): PostingResult
    {
        $property = $unit->property;
        $code = $property->transactionCodes()->where('system_key', $policy->code->value)->where('active', true)->first();

        if ($code === null || $code->kind !== TransactionKind::Charge) {
            return PostingResult::refused(PostingResult::REFUSAL_CHARGE_NOT_FOUND);
        }

        $folio = $this->folioRouter->targetFor($unit, $code);

        if ($folio !== null && $folio->status !== FolioStatus::Open) {
            return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
        }

        $tax = $this->taxCalculator->calculate($property, $code, $fee, '1', $this->unitNationality->for($unit));

        if (! $tax->isCalculated()) {
            return PostingResult::refused((string) $tax->refusalReason);
        }

        $folio ??= ($this->createFolio)($unit->reservation, $unit);
        $key = $keyPrefix.':'.$unit->id.':'.(FolioLine::query()->where('idempotency_key', 'like', $keyPrefix.':'.$unit->id.':%')->whereNull('parent_line_id')->count() + 1);

        return PostingResult::posted(($this->postFolioCharge)($actor, $folio, $code, $description, 1, $fee, $tax, $key, $postingDate, $unit));
    }
}
