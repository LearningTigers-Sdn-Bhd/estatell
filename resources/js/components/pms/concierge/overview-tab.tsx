import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { conciergeTabUrl } from '@/components/pms/concierge/concierge-shell';
import { PanelSection, Status } from '@/components/pms/concierge/guide-layout';
import type { GuideSectionStatus } from '@/components/pms/concierge/guide-sections';
import { CopyLinkButton, LobbyQr } from '@/components/pms/concierge/lobby-qr';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';

/** How complete the guest guide is, with a row per section that opens its tab. */
export function GuideReadiness({ items }: { items: GuideSectionStatus[] }) {
    const ready = items.filter((item) => item.ready).length;
    const remaining = items.length - ready;
    const next = items.find((item) => !item.ready);

    return (
        <PanelSection
            title="Guest guide"
            description="Complete each section so guests can find the property information they need."
            action={
                <Badge variant={remaining === 0 ? 'secondary' : 'outline'}>
                    {ready} of {items.length} ready
                </Badge>
            }
        >
            <div className="flex flex-col gap-4">
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
                        <Link
                            href={conciergeTabUrl(next.key)}
                            className={buttonVariants({
                                variant: 'link',
                                className: 'text-foreground',
                            })}
                        >
                            Continue with {next.label}
                            <ChevronRight data-icon="inline-end" />
                        </Link>
                    )}
                </div>
            </div>
            <ul className="flex flex-col border-t">
                {items.map((item) => (
                    <li key={item.key} className="border-b last:border-b-0">
                        <Link
                            href={conciergeTabUrl(item.key)}
                            className="flex min-h-20 w-full items-center gap-3 rounded-lg px-3 py-3 text-left text-sm transition-colors hover:bg-muted focus-visible:bg-muted active:bg-muted"
                        >
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-foreground">
                                <item.icon aria-hidden className="size-4" />
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
                        </Link>
                    </li>
                ))}
            </ul>
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
