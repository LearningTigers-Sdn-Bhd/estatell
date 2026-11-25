<?php

namespace App\Services\Platform;

use App\Data\Platform\AdminDashboardTodoData;
use App\Data\Platform\AdminDashboardTodoRowData;
use App\Enums\BusinessDateStatus;
use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelPropertyStatus;
use App\Enums\ContactEnquiryStatus;
use App\Enums\MailStatus;
use App\Enums\PropertyStatus;
use App\Enums\SignInResult;
use App\Enums\Tone;
use App\Enums\WebhookDeliveryStatus;
use App\Models\ChannelConnection;
use App\Models\ContactEnquiry;
use App\Models\MailEvent;
use App\Models\Property;
use App\Models\SignInEvent;
use App\Models\WebhookDelivery;
use App\Services\Property\PropertyToday;
use Illuminate\Database\Eloquent\Builder;

/**
 * What needs a platform admin now. Each row counts one problem and links to the list filtered to it. Rows with
 * nothing to count are left out. See docs/01-platform/10-admin-dashboard.md#to-do.
 */
class AdminDashboardTodo
{
    /** Days without onboarding activity, or without a new booking, before a property shows here. */
    private const int QUIET_DAYS = 14;

    public function __construct(
        private PropertyToday $propertyToday,
    ) {}

    public function get(): AdminDashboardTodoData
    {
        $today = now(SignInLogDirectory::TIME_ZONE)->startOfDay();
        $mailFrom = $today->subDays(6)->toDateString();
        $signInFrom = $today->subDay();

        $rows = array_values(array_filter([
            $this->row(
                key: 'pending-approval',
                count: Property::query()->where('status', PropertyStatus::PendingApproval)->count(),
                label: fn (int $count): string => trans_choice('{1} property waits for approval|[2,*] properties wait for approval', $count),
                href: route('admin.properties.index', ['filter' => ['status' => PropertyStatus::PendingApproval->value]], absolute: false),
                tone: Tone::Warning,
            ),
            $this->row(
                key: 'enquiries',
                count: ContactEnquiry::query()->where('status', ContactEnquiryStatus::New)->count(),
                label: fn (int $count): string => trans_choice('{1} new enquiry|[2,*] new enquiries', $count),
                href: route('admin.enquiries.index', ['filter' => ['status' => ContactEnquiryStatus::New->value]], absolute: false),
                tone: Tone::Info,
            ),
            ...$this->nightAuditRows(),
            ...$this->channelRows(),
            ...$this->quietRows(),
            $this->row(
                key: 'stuck-onboarding',
                count: Property::query()
                    ->where('status', PropertyStatus::Onboarding)
                    ->whereRaw('coalesce(onboarding_last_activity_at, created_at) < ?', [now()->subDays(self::QUIET_DAYS)])
                    ->count(),
                label: fn (int $count): string => trans_choice('{1} property stuck in onboarding for 14 days|[2,*] properties stuck in onboarding for 14 days', $count),
                href: route('admin.properties.index', ['filter' => ['status' => PropertyStatus::Onboarding->value]], absolute: false),
                tone: Tone::Warning,
            ),
            ...array_map(fn (WebhookDeliveryStatus $status): ?AdminDashboardTodoRowData => $this->row(
                key: "webhooks-{$status->value}",
                count: WebhookDelivery::query()->where('status', $status)->count(),
                label: fn (int $count): string => $status === WebhookDeliveryStatus::Failed
                    ? trans_choice('{1} webhook delivery failed|[2,*] webhook deliveries failed', $count)
                    : trans_choice('{1} webhook delivery refused|[2,*] webhook deliveries refused', $count),
                href: route('admin.webhooks.deliveries', ['filter' => ['status' => $status->value]], absolute: false),
                tone: Tone::Danger,
            ), [WebhookDeliveryStatus::Failed, WebhookDeliveryStatus::Refused]),
            ...array_map(fn (MailStatus $status): ?AdminDashboardTodoRowData => $this->row(
                key: "mail-{$status->value}",
                count: MailEvent::query()->where('status', $status)->where('sent_at', '>=', $mailFrom.' 00:00:00')->count(),
                label: fn (int $count): string => $status === MailStatus::Bounced
                    ? trans_choice('{1} email bounced in the last 7 days|[2,*] emails bounced in the last 7 days', $count)
                    : trans_choice('{1} email failed in the last 7 days|[2,*] emails failed in the last 7 days', $count),
                href: route('admin.mail-events.index', ['filter' => ['status' => $status->value, 'from' => $mailFrom]], absolute: false),
                tone: Tone::Warning,
            ), [MailStatus::Bounced, MailStatus::Failed]),
            $this->row(
                key: 'lockouts',
                count: SignInEvent::query()
                    ->where('result', SignInResult::LockedOut)
                    ->where('occurred_at', '>=', $signInFrom->utc())
                    ->count(),
                label: fn (int $count): string => trans_choice('{1} sign-in lockout since yesterday|[2,*] sign-in lockouts since yesterday', $count),
                href: route('admin.sign-in-events.index', ['filter' => ['result' => SignInResult::LockedOut->value, 'from' => $signInFrom->toDateString()]], absolute: false),
                tone: Tone::Warning,
            ),
        ]));

        return new AdminDashboardTodoData(
            total: array_sum(array_map(fn (AdminDashboardTodoRowData $row): int => $row->count, $rows)),
            rows: $rows,
        );
    }

    /**
     * One row per Live property whose open business date is more than a day behind its calendar, the same rule as
     * the front desk night audit alert. Linked to the admin property page.
     *
     * @return list<AdminDashboardTodoRowData>
     */
    private function nightAuditRows(): array
    {
        return array_values(Property::query()
            ->where('status', PropertyStatus::Live)
            ->with(['settings', 'businessDates' => fn ($query) => $query->current()])
            ->orderBy('name')
            ->get()
            ->map(function (Property $property): ?AdminDashboardTodoRowData {
                $businessDate = $property->businessDates->first();

                if ($businessDate === null || $businessDate->status !== BusinessDateStatus::Open) {
                    return null;
                }

                $behind = (int) $businessDate->date->toImmutable()->startOfDay()->diffInDays($this->propertyToday->date($property), false);

                if ($behind <= 1) {
                    return null;
                }

                return new AdminDashboardTodoRowData(
                    key: "night-audit-{$property->code}",
                    count: 1,
                    label: __('Night audit :days days behind', ['days' => $behind]),
                    detail: $property->name,
                    href: route('admin.properties.show', $property->code, absolute: false),
                    tone: Tone::Danger,
                );
            })
            ->filter()
            ->all());
    }

    /**
     * One row per property Live for at least 14 days with no reservation created in the last 14 days.
     *
     * @return list<AdminDashboardTodoRowData>
     */
    private function quietRows(): array
    {
        $since = now()->subDays(self::QUIET_DAYS);

        return array_values(Property::query()
            ->where('status', PropertyStatus::Live)
            ->where('went_live_at', '<=', $since)
            ->whereDoesntHave('reservations', fn (Builder $query) => $query->where('created_at', '>=', $since))
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Property $property): AdminDashboardTodoRowData => new AdminDashboardTodoRowData(
                key: "quiet-{$property->code}",
                count: 1,
                label: __('No new bookings in 14 days'),
                detail: $property->name,
                href: route('admin.properties.show', $property->code, absolute: false),
                tone: Tone::Warning,
            ))
            ->all());
    }

    /**
     * One row per property whose channel manager link or a connection is in error, linked to its Channels tab.
     *
     * @return list<AdminDashboardTodoRowData>
     */
    private function channelRows(): array
    {
        $errors = ChannelConnection::query()
            ->where('channel_connections.status', ChannelConnectionStatus::Error)
            ->join('channel_properties', 'channel_properties.id', '=', 'channel_connections.channel_property_id')
            ->selectRaw('channel_properties.property_id, count(*) as errors')
            ->groupBy('channel_properties.property_id')
            ->pluck('errors', 'property_id');

        return array_values(Property::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('id', $errors->keys())
                ->orWhereHas('channelProperty', fn (Builder $channel) => $channel->where('status', ChannelPropertyStatus::Error)))
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(function (Property $property) use ($errors): AdminDashboardTodoRowData {
                $count = max(1, (int) ($errors[$property->id] ?? 0));

                return new AdminDashboardTodoRowData(
                    key: "channels-{$property->code}",
                    count: $count,
                    label: trans_choice('{1} channel error|[2,*] channel errors', $count),
                    detail: $property->name,
                    href: route('admin.properties.channels', $property->code, absolute: false),
                    tone: Tone::Danger,
                );
            })
            ->all());
    }

    /**
     * @param  callable(int): string  $label
     */
    private function row(string $key, int $count, callable $label, string $href, Tone $tone): ?AdminDashboardTodoRowData
    {
        if ($count === 0) {
            return null;
        }

        return new AdminDashboardTodoRowData(key: $key, count: $count, label: $label($count), detail: null, href: $href, tone: $tone);
    }
}
