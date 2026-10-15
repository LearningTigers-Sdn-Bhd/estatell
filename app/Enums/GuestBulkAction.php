<?php

namespace App\Enums;

enum GuestBulkAction: string
{
    case MarkVip = 'mark-vip';
    case UnmarkVip = 'unmark-vip';
    case Blacklist = 'blacklist';
    case Unblacklist = 'unblacklist';
    case Anonymize = 'anonymize';

    public function label(): string
    {
        return match ($this) {
            self::MarkVip => __('Mark as VIP'),
            self::UnmarkVip => __('Unmark as VIP'),
            self::Blacklist => __('Blacklist'),
            self::Unblacklist => __('Remove from blacklist'),
            self::Anonymize => __('Archive guests'),
        };
    }

    public function permission(): PermissionEnum
    {
        return match ($this) {
            self::MarkVip, self::UnmarkVip => PermissionEnum::GuestsManage,
            self::Blacklist, self::Unblacklist => PermissionEnum::GuestsBlacklist,
            self::Anonymize => PermissionEnum::GuestsAnonymize,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $action): array => ['value' => $action->value, 'label' => $action->label()], self::cases());
    }

    public function summary(int $changed, int $unchanged, int $skipped): string
    {
        $message = trans_choice(match ($this) {
            self::MarkVip => '{0} No guests marked as VIP.|{1} :count guest marked as VIP.|[2,*] :count guests marked as VIP.',
            self::UnmarkVip => '{0} No guests unmarked as VIP.|{1} :count guest unmarked as VIP.|[2,*] :count guests unmarked as VIP.',
            self::Blacklist => '{0} No guests blacklisted.|{1} :count guest blacklisted.|[2,*] :count guests blacklisted.',
            self::Unblacklist => '{0} No guests removed from the blacklist.|{1} :count guest removed from the blacklist.|[2,*] :count guests removed from the blacklist.',
            self::Anonymize => '{0} No guests archived.|{1} :count guest archived.|[2,*] :count guests archived.',
        }, $changed);

        if ($unchanged > 0) {
            $message .= ' '.__(':count already in the requested state.', ['count' => $unchanged]);
        }

        if ($skipped > 0) {
            $message .= ' '.__(':count skipped. Open their guest pages to review them.', ['count' => $skipped]);
        }

        return $message;
    }
}
