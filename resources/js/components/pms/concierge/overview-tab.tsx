import { ChevronRight, ExternalLink } from 'lucide-react';
import { GuestActionsCard } from '@/components/pms/concierge/guest-actions-card';
import { ContentGrid, Status } from '@/components/pms/concierge/guide-layout';
import type { GuideSectionStatus } from '@/components/pms/concierge/guide-sections';
import { CopyLinkButton, LobbyQr } from '@/components/pms/concierge/lobby-qr';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';

/** How complete the guest guide is, a row per section to jump to, and the lobby QR code. */
export function OverviewTab({
    items,
    lobbyUrl,
    lobbyQr,
    houseRules,
    walkInEnabled,
    refundRequestsEnabled,
    locationCheckEnabled,
    hasLocation,
    onOpen,
}: {
    items: GuideSectionStatus[];
    lobbyUrl: string;
    lobbyQr: string;
    houseRules: string | null;
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
    locationCheckEnabled: boolean;
    hasLocation: boolean;
    onOpen: (tab: string) => void;
}) {
    const ready = items.filter((item) => item.ready).length;
    const remaining = items.length - ready;
    const next = items.find((item) => !item.ready);

    return (
        <ContentGrid
            aside={
                <>
                    <Card>
                        <CardHeader>
                            <CardTitle>Lobby check-in</CardTitle>
                            <CardDescription>
                                Display this code where guests arrive. It opens
                                the lobby page on their phone.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col items-center gap-4">
                            <LobbyQr
                                url={lobbyUrl}
                                qr={lobbyQr}
                                className="size-40"
                            />
                        </CardContent>
                        <CardFooter className="flex-wrap justify-center gap-2">
                            <a
                                href={lobbyUrl}
                                target="_blank"
                                rel="noreferrer"
                                className={buttonVariants({
                                    variant: 'outline',
                                    className: 'min-h-11',
                                })}
                            >
                                <ExternalLink data-icon="inline-start" />
                                Open lobby page
                            </a>
                            <CopyLinkButton url={lobbyUrl} />
                        </CardFooter>
                    </Card>
                    <GuestActionsCard
                        houseRules={houseRules}
                        walkInEnabled={walkInEnabled}
                        refundRequestsEnabled={refundRequestsEnabled}
                        locationCheckEnabled={locationCheckEnabled}
                        hasLocation={hasLocation}
                    />
                </>
            }
        >
            <Card>
                <CardHeader>
                    <CardTitle>Guest guide</CardTitle>
                    <CardDescription>
                        Complete each section so guests can find the property
                        information they need.
                    </CardDescription>
                    <CardAction>
                        <Badge
                            variant={remaining === 0 ? 'secondary' : 'outline'}
                        >
                            {ready} of {items.length} ready
                        </Badge>
                    </CardAction>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    <Progress
                        value={(ready / items.length) * 100}
                        aria-label="Guest guide readiness"
                    />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            {remaining === 0
                                ? 'Every section is ready for guests.'
                                : `${remaining} ${remaining === 1 ? 'section needs' : 'sections need'} details.`}
                        </p>
                        {next && (
                            <Button
                                type="button"
                                variant="link"
                                className="min-h-11 text-foreground"
                                onClick={() => onOpen(next.key)}
                            >
                                Continue with {next.label}
                                <ChevronRight data-icon="inline-end" />
                            </Button>
                        )}
                    </div>
                </CardContent>
                <CardContent className="p-0">
                    <ul className="flex flex-col border-t">
                        {items.map((item) => (
                            <li
                                key={item.key}
                                className="border-b last:border-b-0"
                            >
                                <button
                                    type="button"
                                    onClick={() => onOpen(item.key)}
                                    className="flex min-h-20 w-full items-center gap-3 px-4 py-3 text-left text-sm transition-colors hover:bg-muted focus-visible:bg-muted active:bg-muted"
                                >
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-foreground">
                                        <item.icon
                                            aria-hidden
                                            className="size-4"
                                        />
                                    </span>
                                    <span className="flex min-w-0 flex-1 flex-col gap-1">
                                        <span className="font-medium">
                                            {item.label}
                                        </span>
                                        <span className="text-pretty text-muted-foreground">
                                            {item.detail}
                                        </span>
                                        <span className="pt-1 sm:hidden">
                                            <Status ready={item.ready} />
                                        </span>
                                    </span>
                                    <span className="hidden sm:block">
                                        <Status ready={item.ready} />
                                    </span>
                                    <ChevronRight
                                        aria-hidden
                                        className="size-4 shrink-0 text-muted-foreground"
                                    />
                                </button>
                            </li>
                        ))}
                    </ul>
                </CardContent>
            </Card>
        </ContentGrid>
    );
}
