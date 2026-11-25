<?php

namespace App\Services\AiAssistant;

use App\Data\AiAssistant\AiThreadRowData;
use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use App\Models\AiThread;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Laravel\Ai\Models\ConversationMessage;

/**
 * The AI chats inbox lists, guest places only (never a staff member's own helper chats). Handed over: Escalated threads, oldest handover first. All: every thread, last message
 * first. Both filter by place and by the day of the last message, 25 a page, scoped to the property. Each row also gets
 * the guest's last message and its turn count in the same query. See docs/35-ai-assistant/03-places-and-routes.md#ai-chats-inbox.
 */
class AiThreadList
{
    public const int PER_PAGE = 25;

    /**
     * @param  array{place?: string, from?: string, to?: string}  $filters
     * @return LengthAwarePaginator<int, AiThread>
     */
    public function handedOver(Property $property, array $filters): LengthAwarePaginator
    {
        return $this->query($property, $filters)
            ->where('status', AiThreadStatus::Escalated)
            ->orderBy('escalated_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @param  array{place?: string, from?: string, to?: string}  $filters
     * @return LengthAwarePaginator<int, AiThread>
     */
    public function all(Property $property, array $filters): LengthAwarePaginator
    {
        return $this->query($property, $filters)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    public function handedOverCount(Property $property): int
    {
        return AiThread::query()->where('property_id', $property->id)->whereIn('place', AiPlace::guestPlaces())->where('status', AiThreadStatus::Escalated)->count();
    }

    /**
     * One thread of the property with what its row needs, or null when it is not this property's.
     */
    public function find(Property $property, string $threadId): ?AiThread
    {
        if (! str()->isUuid($threadId)) {
            return null;
        }

        return $this->query($property, [])->with('resolvedBy:id,name')->find($threadId);
    }

    /**
     * @param  array{place?: string, from?: string, to?: string}  $filters
     * @return Builder<AiThread>
     */
    private function query(Property $property, array $filters): Builder
    {
        $place = AiPlace::tryFrom($filters['place'] ?? '');
        $from = $filters['from'] ?? '';
        $to = $filters['to'] ?? '';

        return AiThread::query()
            ->where('property_id', $property->id)
            ->whereIn('place', AiPlace::guestPlaces())
            ->when($place !== null, fn (Builder $query) => $query->where('place', $place))
            ->when($from !== '', fn (Builder $query) => $query->where('last_message_at', '>=', $this->dayStart($property, $from)))
            ->when($to !== '', fn (Builder $query) => $query->where('last_message_at', '<', $this->dayStart($property, $to)->addDay()))
            ->select('ai_threads.*')
            ->addSelect(['last_guest_message' => ConversationMessage::query()
                ->select('content')
                ->whereColumn('conversation_id', 'ai_threads.agent_conversation_id')
                ->where('role', 'user')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(1)])
            ->withCount('turns')
            ->with(AiThreadRowData::RELATIONS);
    }

    /**
     * The start of a property day, in the app time zone the timestamps use. A date that does not parse is today.
     */
    private function dayStart(Property $property, string $date): CarbonImmutable
    {
        $timeZone = $property->loadMissing('settings')->settings->time_zone ?? config('app.timezone');

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timeZone);
        } catch (InvalidFormatException) {
            $day = false;
        }

        return ($day === false ? now($timeZone)->startOfDay() : $day)->setTimezone(config('app.timezone'));
    }
}
