import { Link } from '@inertiajs/react';
import { BedDouble, MapPinCheck, ReceiptText } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { PanelSection } from '@/components/pms/concierge/guide-layout';
import { AutoSaveStatus } from '@/components/pms/settings/auto-save-status';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useAutoSave } from '@/hooks/use-auto-save';
import { profile } from '@/routes/settings';
import { update } from '@/routes/settings/concierge';

type Switches = {
    walk_in_enabled: boolean;
    refund_requests_enabled: boolean;
    location_check_enabled: boolean;
};

type GuestAction = {
    name: keyof Switches;
    icon: LucideIcon;
    title: string;
    body: ReactNode;
};

/**
 * What guests can do from the lobby and stay pages: a table with the switch on the left, like the admin Features tab. Each switch saves by
 * itself (`useAutoSave`); house rules are sent along unchanged.
 */
export function GuestActionsCard({
    houseRules,
    walkInEnabled,
    refundRequestsEnabled,
    locationCheckEnabled,
    hasLocation,
}: {
    houseRules: string | null;
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
    locationCheckEnabled: boolean;
    hasLocation: boolean;
}) {
    const form = useAutoSave<Switches>(
        {
            walk_in_enabled: walkInEnabled,
            refund_requests_enabled: refundRequestsEnabled,
            location_check_enabled: locationCheckEnabled,
        },
        (value) => ({
            url: update.url(),
            data: { ...value, house_rules: houseRules ?? '' },
        }),
    );

    const actions: GuestAction[] = [
        {
            name: 'walk_in_enabled',
            icon: BedDouble,
            title: 'Guests with no booking',
            body: 'Let a guest book a room for tonight on the lobby page. The room is held for 10 minutes and released if it is not paid for.',
        },
        {
            name: 'refund_requests_enabled',
            icon: ReceiptText,
            title: 'Refund requests',
            body: 'Let a guest ask for a refund after check-out. It reaches the Requests list for staff to answer. Nothing is refunded automatically.',
        },
        {
            name: 'location_check_enabled',
            icon: MapPinCheck,
            title: 'Check in at the property only',
            body: hasLocation ? (
                'Ask the guest to allow location access, and check in only within 50 m of the property.'
            ) : (
                <>
                    Add the property location in the{' '}
                    <Link
                        href={profile()}
                        className="font-medium text-primary underline underline-offset-4 hover:text-primary/80"
                    >
                        property profile
                    </Link>{' '}
                    first. Without it, this has no effect.
                </>
            ),
        },
    ];

    return (
        <PanelSection
            title="Guest actions"
            description="Choose what guests can do from the lobby and stay pages."
            action={<AutoSaveStatus saving={form.saving} />}
        >
            <div className="overflow-x-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-16">On</TableHead>
                            <TableHead>Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {actions.map((action) => {
                            const checked = form.value[action.name];

                            return (
                                <TableRow key={action.name}>
                                    <TableCell>
                                        <Switch
                                            size="sm"
                                            checked={checked}
                                            aria-label={`Turn ${action.title} ${checked ? 'off' : 'on'}`}
                                            onCheckedChange={(next) =>
                                                form.change({
                                                    ...form.value,
                                                    [action.name]: next,
                                                })
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="whitespace-normal">
                                        <div className="flex min-w-0 items-start gap-2">
                                            <action.icon
                                                aria-hidden
                                                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                            />
                                            <div className="flex min-w-0 flex-col gap-0.5">
                                                <span className="font-medium">
                                                    {action.title}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {action.body}
                                                </span>
                                            </div>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
        </PanelSection>
    );
}
