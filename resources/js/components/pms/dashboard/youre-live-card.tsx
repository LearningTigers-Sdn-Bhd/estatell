import { Check } from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { ExportTemporaryPasswordsMenu } from '@/components/pms/temporary-passwords/export-temporary-passwords-menu';
import { TemporaryPasswordMessageDialog } from '@/components/pms/temporary-passwords/temporary-password-message-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { exportMethod, message } from '@/routes/settings/staff/temp-passwords';

type YoureLive = App.Data.Onboarding.YoureLiveData;

function todayIsoDate(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

/**
 * The You're live card on the dashboard. Shows the staff who still hold a go-live temp password and lets the owner
 * share their sign-in details. With no pending staff it shows only the opening line, and only on the visit that
 * follows going live (`wentLive` flash). Staff who were shared drop off the card on the next visit.
 */
export function YoureLiveCard({
    youreLive,
    wentLive,
    canShare,
}: {
    youreLive: YoureLive;
    wentLive: boolean;
    /** False during support access, where the server blocks the staff temp password routes. */
    canShare: boolean;
}) {
    const [sharedOn, setSharedOn] = useState<string | null>(null);
    const [messageOpen, setMessageOpen] = useState(false);

    const hasPending = youreLive.pendingCount > 0;
    const everyoneShared = youreLive.notSharedCount === 0 && sharedOn === null;

    if (!hasPending && !wentLive) {
        return null;
    }

    if (hasPending && everyoneShared) {
        return null;
    }

    const share = () => setSharedOn(todayIsoDate());

    return (
        <Card>
            <CardHeader>
                <CardTitle>You're live!</CardTitle>
                <CardDescription>
                    {youreLive.propertyName} is open.
                    {youreLive.firstBusinessDate !== null && (
                        <>
                            {' '}
                            Your first business day is{' '}
                            <DateText date={youreLive.firstBusinessDate} />.
                        </>
                    )}
                </CardDescription>
            </CardHeader>

            {hasPending && (
                <CardContent className="flex flex-col gap-3">
                    <p className="font-medium">Share sign-in details</p>

                    <div className="flex flex-col gap-1">
                        <p className="text-sm">
                            <span className="font-medium">
                                Team ({youreLive.pendingCount})
                            </span>{' '}
                            <span className="text-muted-foreground">
                                {sharedOn !== null ? (
                                    <span className="inline-flex items-center gap-1 text-primary">
                                        <Check className="size-4" />
                                        Shared <DateText date={sharedOn} />
                                    </span>
                                ) : youreLive.notSharedCount ===
                                  youreLive.pendingCount ? (
                                    'Not shared yet'
                                ) : (
                                    `${youreLive.notSharedCount} not shared yet`
                                )}
                            </span>
                        </p>

                        {youreLive.expiresOn !== null && (
                            <p className="text-sm text-muted-foreground">
                                Passwords expire on{' '}
                                <DateText date={youreLive.expiresOn} />. You can
                                reset them in Settings, under Staff.
                            </p>
                        )}
                    </div>

                    {canShare && (
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Button
                                variant="outline"
                                className="w-full sm:w-auto"
                                onClick={() => setMessageOpen(true)}
                            >
                                {sharedOn !== null
                                    ? 'Copy again'
                                    : 'Copy messages'}
                            </Button>
                            <ExportTemporaryPasswordsMenu
                                route={exportMethod()}
                                userIds={youreLive.userIds}
                                formats={youreLive.exportFormats}
                                onExported={share}
                            />
                        </div>
                    )}

                    {canShare && (
                        <TemporaryPasswordMessageDialog
                            route={message()}
                            who="team"
                            userIds={youreLive.userIds}
                            open={messageOpen}
                            onOpenChange={setMessageOpen}
                            onCopied={share}
                        />
                    )}
                </CardContent>
            )}
        </Card>
    );
}
