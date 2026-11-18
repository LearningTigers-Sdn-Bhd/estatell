import { Link } from '@inertiajs/react';
import { ChevronRight, ListChecks } from 'lucide-react';
import { conciergeTabUrl } from '@/components/pms/concierge/concierge-shell';
import { PanelSection, Status } from '@/components/pms/concierge/guide-layout';
import type { GuideSectionStatus } from '@/components/pms/concierge/guide-sections';
import { CopyLinkButton, LobbyQr } from '@/components/pms/concierge/lobby-qr';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Progress } from '@/components/ui/progress';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

/** How many guest guide sections are ready, as a progress bar in an alert at the top of the rail. */
export function GuideProgress({ items }: { items: GuideSectionStatus[] }) {
    const ready = items.filter((item) => item.ready).length;

    return (
        <Alert role="status">
            <ListChecks />
            <AlertTitle>
                {ready} of {items.length} ready
            </AlertTitle>
            <AlertDescription>
                <Progress
                    value={(ready / items.length) * 100}
                    aria-label="Guest guide readiness"
                    className="mt-1.5"
                />
            </AlertDescription>
        </Alert>
    );
}

/** A table row per guest guide section that opens its tab. */
export function GuideReadiness({ items }: { items: GuideSectionStatus[] }) {
    return (
        <PanelSection
            title="Guest guide"
            description="Complete each section so guests can find the property information they need."
        >
            <div className="overflow-x-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-32">Status</TableHead>
                            <TableHead>Section</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {items.map((item) => (
                            <TableRow key={item.key} className="relative">
                                <TableCell>
                                    <Status ready={item.ready} />
                                </TableCell>
                                <TableCell className="whitespace-normal">
                                    <div className="flex min-w-0 items-start gap-2">
                                        <item.icon
                                            aria-hidden
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        />
                                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <Link
                                                href={conciergeTabUrl(item.key)}
                                                className="rounded-sm font-medium outline-none after:absolute after:inset-0 focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                {item.label}
                                            </Link>
                                            <span className="text-xs text-muted-foreground">
                                                {item.detail}
                                            </span>
                                        </div>
                                        <ChevronRight
                                            aria-hidden
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        />
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </PanelSection>
    );
}

/** The lobby QR code to display where guests arrive, with its link. */
export function LobbyCheckIn({
    lobbyUrl,
    lobbyQr,
}: {
    lobbyUrl: string;
    lobbyQr: string;
}) {
    return (
        <PanelSection
            title="Lobby check-in"
            description="Display this code where guests arrive. It opens the lobby page on their phone."
        >
            <div className="flex flex-col items-center gap-4">
                <LobbyQr url={lobbyUrl} qr={lobbyQr} className="size-40" />
                <CopyLinkButton url={lobbyUrl} />
            </div>
        </PanelSection>
    );
}
