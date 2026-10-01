import { Link } from '@inertiajs/react';
import { MergeGuestDialog } from '@/components/pms/guests/merge-guest-dialog';
import { StatusBadge } from '@/components/pms/status-badge';
import { show } from '@/routes/guests';

/**
 * Guests that may be the same person as this one. Staff with `guests.merge` can merge a duplicate into this guest.
 * Weak matches (same name only) are listed below the strong ones.
 */
export function PossibleDuplicates({
    guest,
    duplicates,
    canMerge,
}: {
    guest: App.Data.Guests.GuestData;
    duplicates: App.Data.Guests.GuestMatchData[];
    canMerge: boolean;
}) {
    if (duplicates.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-medium text-muted-foreground">
                Possible duplicates
            </h2>
            <ul className="flex flex-col gap-2">
                {duplicates.map((duplicate) => (
                    <li
                        key={duplicate.id}
                        className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
                    >
                        <div className="flex min-w-0 flex-col gap-1">
                            <span className="flex flex-wrap items-center gap-2">
                                <Link
                                    href={show.url({ guest: duplicate.id })}
                                    className="text-sm font-medium underline-offset-4 hover:underline"
                                >
                                    {duplicate.fullName}
                                </Link>
                                {duplicate.isVip && (
                                    <StatusBadge
                                        status={{
                                            value: 'vip',
                                            label: 'VIP',
                                            tone: 'info',
                                        }}
                                    />
                                )}
                                {duplicate.isBlacklisted && (
                                    <StatusBadge
                                        status={{
                                            value: 'blacklisted',
                                            label: 'Blacklisted',
                                            tone: 'danger',
                                        }}
                                    />
                                )}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {[duplicate.phone, duplicate.email]
                                    .filter(Boolean)
                                    .join(' · ') || 'No contact details'}
                                {' — '}
                                {duplicate.reasons.join(', ')}
                            </span>
                        </div>
                        {canMerge && (
                            <MergeGuestDialog
                                survivor={guest}
                                duplicate={duplicate}
                            />
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
