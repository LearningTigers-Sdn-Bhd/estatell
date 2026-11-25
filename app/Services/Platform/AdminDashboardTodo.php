<?php

namespace App\Services\Platform;

use App\Data\Platform\AdminDashboardTodoData;
use App\Data\Platform\AdminDashboardTodoRowData;
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
use Illuminate\Database\Eloquent\Builder;

/**
 * What needs a platform admin now. Each row counts one problem and links to the list filtered to it. Rows with
 * nothing to count are left out. See docs/01-platform/10-admin-dashboard.md#to-do.
 */
class AdminDashboardTodo
{
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
            ...$this->channelRows(),
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

        return Property::query()
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
            ->values()
            ->all();
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
