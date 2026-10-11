<?php

namespace App\Services\Folio;

use App\Enums\ArInvoiceStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PermissionEnum;
use App\Enums\UnitStatus;
use App\Models\ArInvoice;
use App\Models\Folio;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;

/**
 * Who may reopen a folio, and what a Closed folio tells staff. `folio.reopen` may reopen any folio. A user with
 * `front-desk.check-out` may also reopen a Unit folio closed during the current business date, because most late
 * charges are found the same day; after the night audit the day is final and a manager reopens. A folio with an AR
 * invoice that is not Void never reopens: `ReopenFolio` refuses it, and the notice points to a third-party folio or a
 * credit note. It reads only. See docs/13-folio/01-folios-and-lines.md#close-and-reopen.
 */
class FolioReopenRule
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
    ) {}

    /**
     * Whether the user passes the permission part of the rule. A folio that is not Closed passes for the same users,
     * and `ReopenFolio` then refuses it with its own code.
     */
    public function allows(User $user, Folio $folio): bool
    {
        if ($user->can(PermissionEnum::FolioReopen->value)) {
            return true;
        }

        if (! $user->can(PermissionEnum::FrontDeskCheckOut->value) || $folio->type !== FolioType::Unit) {
            return false;
        }

        return $folio->status !== FolioStatus::Closed || $this->closedThisBusinessDate($folio);
    }

    /**
     * The AR invoice that bills a Closed folio and keeps it from reopening: the oldest one that is not Void.
     */
    public function billingInvoice(Folio $folio): ?ArInvoice
    {
        if ($folio->status !== FolioStatus::Closed) {
            return null;
        }

        return ArInvoice::query()->where('folio_id', $folio->id)->where('status', '<>', ArInvoiceStatus::Void)->with('company:id,name')->oldest('id')->first();
    }

    /**
     * The notice above the rows of a Closed folio, or null for any other folio.
     */
    public function notice(User $user, Folio $folio): ?string
    {
        if ($folio->status !== FolioStatus::Closed) {
            return null;
        }

        $invoice = $this->billingInvoice($folio);

        if ($invoice !== null) {
            return __('Billed to :company on invoice :number. Use a third-party folio or a credit note.', [
                'company' => $invoice->company->name,
                'number' => $invoice->number,
            ]);
        }

        $property = $folio->loadMissing('property.settings')->property;
        $timeZone = $property->settings?->time_zone ?? config('app.timezone');
        $closedAt = $folio->closed_at === null ? null : CarbonImmutable::parse($folio->closed_at)->setTimezone($timeZone);
        $today = $this->propertyToday->date($property);
        $when = match (true) {
            $closedAt === null => '',
            $closedAt->toDateString() === $today->toDateString() => __('today, :time', ['time' => $closedAt->format('H:i')]),
            $closedAt->toDateString() === $today->subDay()->toDateString() => __('yesterday'),
            default => __('on :date', ['date' => $closedAt->format('j M')]),
        };
        $text = $this->atCheckOut($folio)
            ? __('Closed at check-out :when.', ['when' => $when])
            : __('Closed :when.', ['when' => $when]);

        return str_replace(' .', '.', $text).' '.($this->allows($user, $folio)
            ? __('To add a charge, refund or fix a line, reopen it.')
            : __('Ask a manager to reopen it.'));
    }

    private function closedThisBusinessDate(Folio $folio): bool
    {
        $opened = $this->currentBusinessDate->get($folio->loadMissing('property')->property)?->opened_at;

        return $opened !== null && $folio->closed_at !== null && $folio->closed_at->greaterThanOrEqualTo($opened);
    }

    /**
     * A Unit folio closed within a minute of its unit's check-out was closed by the check-out.
     */
    private function atCheckOut(Folio $folio): bool
    {
        if ($folio->type !== FolioType::Unit || $folio->closed_at === null) {
            return false;
        }

        $unit = $folio->reservationUnit()->first();

        return $unit !== null
            && $unit->status === UnitStatus::CheckedOut
            && $unit->checked_out_at !== null
            && abs($folio->closed_at->diffInSeconds($unit->checked_out_at)) <= 60;
    }
}
