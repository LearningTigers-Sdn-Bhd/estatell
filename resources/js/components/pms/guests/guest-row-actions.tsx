import { Link, router } from '@inertiajs/react';
import {
    Ban,
    Ellipsis,
    ExternalLink,
    ShieldCheck,
    Star,
    StarOff,
    UserX,
} from 'lucide-react';
import { useState } from 'react';
import { ArchiveGuestDialog } from '@/components/pms/guests/anonymize-guest-dialog';
import {
    BlacklistDialog,
    UnblacklistDialog,
} from '@/components/pms/guests/guest-actions';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { show } from '@/routes/guests';
import { destroy as unmarkVip, store as markVip } from '@/routes/guests/vip';

type Row = App.Data.Guests.GuestRowData;

export type GuestRowPermissions = {
    canManage: boolean;
    canBlacklist: boolean;
    canAnonymize: boolean;
};

/**
 * The ⋯ menu at the end of a guest list row. It shows only the actions the user may take, and uses the same routes and
 * dialogs as the guest page. Clicks stay inside it, so they never open the guest.
 */
export function GuestRowActions({
    row,
    canManage,
    canBlacklist,
    canAnonymize,
}: { row: Row } & GuestRowPermissions) {
    const [dialog, setDialog] = useState<
        'blacklist' | 'unblacklist' | 'archive' | null
    >(null);
    const close = (open: boolean) => !open && setDialog(null);
    const active = !row.isAnonymized;
    const guest = { id: row.id, fullName: row.fullName };

    const toggleVip = () =>
        row.isVip
            ? router.delete(unmarkVip.url({ guest: row.id }), {
                  preserveScroll: true,
              })
            : router.post(
                  markVip.url({ guest: row.id }),
                  {},
                  { preserveScroll: true },
              );

    return (
        <span
            className="inline-flex"
            onClick={(event) => event.stopPropagation()}
        >
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={<Button variant="ghost" size="icon-sm" />}
                    aria-label={`Actions for ${row.fullName}`}
                >
                    <Ellipsis />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-auto min-w-48">
                    <DropdownMenuItem
                        render={<Link href={show.url({ guest: row.id })} />}
                    >
                        <ExternalLink />
                        Open guest
                    </DropdownMenuItem>
                    {active && canManage && (
                        <DropdownMenuItem onClick={toggleVip}>
                            {row.isVip ? <StarOff /> : <Star />}
                            {row.isVip ? 'Remove VIP' : 'Mark as VIP'}
                        </DropdownMenuItem>
                    )}
                    {active && canBlacklist && (
                        <DropdownMenuItem
                            onClick={() =>
                                setDialog(
                                    row.isBlacklisted
                                        ? 'unblacklist'
                                        : 'blacklist',
                                )
                            }
                        >
                            {row.isBlacklisted ? <ShieldCheck /> : <Ban />}
                            {row.isBlacklisted
                                ? 'Remove from blacklist'
                                : 'Blacklist…'}
                        </DropdownMenuItem>
                    )}
                    {active && canAnonymize && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onClick={() => setDialog('archive')}
                            >
                                <UserX />
                                Archive…
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            {active && canBlacklist && (
                <>
                    <BlacklistDialog
                        guest={guest}
                        open={dialog === 'blacklist'}
                        onOpenChange={close}
                    />
                    <UnblacklistDialog
                        guest={guest}
                        open={dialog === 'unblacklist'}
                        onOpenChange={close}
                    />
                </>
            )}
            {active && canAnonymize && (
                <ArchiveGuestDialog
                    guest={guest}
                    open={dialog === 'archive'}
                    onOpenChange={close}
                />
            )}
        </span>
    );
}
