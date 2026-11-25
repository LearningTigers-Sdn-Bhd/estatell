<?php

namespace App\Actions\AiAssistant;

use App\Models\Property;
use App\Models\User;
use App\Notifications\AiCapNearlyReached;
use App\Services\AiAssistant\AiMonthlyCap;
use App\Services\AiAssistant\AiMonthlyUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Tell staff who manage settings, once a month, that the AI assistant has spent 80% of its monthly cap. Called after
 * each turn is logged. No cap, under 80%, or already told this month (property time zone) changes nothing. Returns
 * whether it told them. See docs/35-ai-assistant/04-usage-and-cost.md#monthly-cap.
 */
class WarnAiCapNearlyReached
{
    public const int PERCENT = 80;

    public function __construct(private readonly AiMonthlyUsage $usage, private readonly AiMonthlyCap $cap) {}

    public function __invoke(Property $property): bool
    {
        $settings = $property->loadMissing('settings')->settings;
        $capMicros = $settings?->ai_monthly_cap_micros;

        if ($settings === null || $capMicros === null) {
            return false;
        }

        $monthStart = now($settings->time_zone)->startOfMonth();

        if ($settings->ai_cap_warned_at !== null && $settings->ai_cap_warned_at->greaterThanOrEqualTo($monthStart)) {
            return false;
        }

        $spentMicros = $this->usage->micros($property);

        if ($spentMicros * 100 < $capMicros * self::PERCENT) {
            return false;
        }

        // Claim this month's notice in one update, so two turns ending together send it once.
        $claimed = $settings->newQuery()
            ->whereKey($settings->getKey())
            ->where(fn (Builder $query) => $query->whereNull('ai_cap_warned_at')->orWhere('ai_cap_warned_at', '<', $monthStart->utc()))
            ->update(['ai_cap_warned_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        $message = new AiCapNearlyReached(
            $property->code,
            $property->name,
            (string) $this->cap->toDollars($spentMicros),
            (string) $this->cap->toDollars($capMicros),
        );

        Notification::send($this->staff($property, $message), $message);

        return true;
    }

    /**
     * The active staff of the property who may manage its settings.
     *
     * @return Collection<int, User>
     */
    private function staff(Property $property, AiCapNearlyReached $message): Collection
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($property->id);

        try {
            return User::query()
                ->where('property_id', $property->id)
                ->where('is_admin', false)
                ->whereNull('deactivated_at')
                ->permission((string) $message->type->recipientPermission())
                ->get();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
