<?php

namespace App\Actions\CityLedger;

use App\Enums\CompanyUserStatus;
use App\Enums\UnitStatus;
use App\Models\AgentPaymentReminder as SentReminder;
use App\Models\CompanyUser;
use App\Models\Reservation;
use App\Notifications\AgentPaymentReminder;
use App\Services\Notifications\MailActorResolver;
use App\Services\Reservations\AgentBill;
use App\Services\Reservations\AgentPaymentSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;

class SendAgentPaymentReminders
{
    public function __construct(private AgentPaymentSchedule $schedule, private AgentBill $agentBill) {}

    /**
     * Email the agents of the open bookings with a payment schedule: the schedule once after booking (unless already paid), a reminder when
     * the first stage not paid is due within a day, and a notice once it is overdue. Each email goes once per stage
     * (a row in `agent_payment_reminders`). A booking whose company has no email is skipped and tried again on the
     * next run. A booking stays in the run after check-out while the agent still owes on an Open folio. Returns how many emails were sent.
     */
    public function __invoke(?CarbonImmutable $now = null): int
    {
        $now ??= now()->toImmutable();
        $sent = 0;

        Reservation::query()
            ->whereNotNull('deposit_due_at')
            ->agentPaymentsOpen()
            ->with(['company', 'property.settings', 'booker'])
            ->withMin(['units as first_arrival' => fn ($units) => $units->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))], 'arrival_date')
            ->orderBy('id')
            ->chunkById(100, function ($reservations) use ($now, &$sent): void {
                foreach ($reservations as $reservation) {
                    $sent += $this->remind($reservation, $now);
                }
            });

        return $sent;
    }

    private function remind(Reservation $reservation, CarbonImmutable $now): int
    {
        $emails = $this->emailsOf($reservation);

        $firstArrival = $reservation->getAttribute('first_arrival');

        if ($emails === [] || ! is_string($firstArrival)) {
            return 0;
        }

        $zone = $reservation->property->settings?->time_zone ?: config('app.timezone');
        $stages = $this->schedule->stages($reservation, $now);
        $next = $this->schedule->nextDue($reservation, $now);
        $already = SentReminder::query()->where('reservation_id', $reservation->id)->get()->map(fn (SentReminder $row): string => $row->stage.':'.$row->kind)->flip();
        $due = fn (CarbonImmutable $at): string => $at->setTimezone($zone)->format('j M Y, H:i');
        $toSend = [];
        $bill = $this->agentBill->for($reservation);
        $billText = fn (array $lines): array => array_values(array_map(fn (array $line): string => $line['label'].($line['quantity'] > 1 ? ' × '.$line['quantity'] : '').': '.$line['amount'], $lines));

        if ($next !== null && ! $already->has('all:schedule')) {
            $toSend[] = ['all', 'schedule'];
        }

        if ($next !== null) {
            $kind = $next['overdue'] ? 'overdue' : ($now->greaterThanOrEqualTo($next['due_at']->subDay()) ? 'reminder' : null);

            if ($kind !== null && ! $already->has($next['kind'].':'.$kind)) {
                $toSend[] = [$next['kind'], $kind];
            }
        }

        foreach ($toSend as [$stage, $kind]) {
            Context::scope(fn () => Notification::route('mail', $emails)->notify(new AgentPaymentReminder(
                kind: $kind,
                propertyName: $reservation->property->name,
                propertyCode: $reservation->property->code,
                reservationId: $reservation->id,
                reference: $reservation->reference,
                arrival: CarbonImmutable::parse($firstArrival)->format('j M Y'),
                stages: array_map(fn (array $one): array => [
                    'label' => match ($one['kind']) {
                        'deposit' => __('Deposit :percent%', ['percent' => $reservation->deposit_percent]),
                        'balance' => __('Balance'),
                        default => __('Full payment'),
                    },
                    'amount' => $one['amount'],
                    'due' => $due($one['due_at']),
                ], $stages),
                amountDue: $next['amount_due'] ?? '0.00',
                dueText: $next === null ? '' : $due($next['due_at']),
                agentLines: $billText($bill->agentLines),
                guestLines: $billText($bill->guestLines),
            )), hidden: [MailActorResolver::PROPERTY_CONTEXT_KEY => $reservation->property_id]);

            $row = new SentReminder;
            $row->forceFill(['reservation_id' => $reservation->id, 'stage' => $stage, 'kind' => $kind, 'sent_at' => $now])->save();
        }

        return count($toSend);
    }

    /**
     * The company's contact email and the emails of its active portal logins.
     *
     * @return list<string>
     */
    private function emailsOf(Reservation $reservation): array
    {
        $company = $reservation->company;

        if ($company === null) {
            return [];
        }

        $logins = CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('status', CompanyUserStatus::Active->value)
            ->with('user:id,email')
            ->get()
            ->map(fn (CompanyUser $link): ?string => $link->user?->email)
            ->all();

        return array_values(array_unique(array_filter([$company->contact_email, ...$logins])));
    }
}
