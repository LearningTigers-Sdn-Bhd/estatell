import { Link, useHttp } from '@inertiajs/react';
import { Check, Copy, MessageCircle, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import PropertyOtaListController from '@/actions/App/Http/Controllers/Admin/PropertyOtaListController';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { otaLists } from '@/routes/admin/properties';

type Connect = App.Data.Channels.ChannelConnectPageData;

/**
 * The reference rail of the Connect page: the owner's OTA lists row and the owner's contact. It is read only. Nothing
 * typed in the Connect form goes back to OTA lists.
 */
export function ConnectRail({
    code,
    connect,
    hotelId,
}: {
    code: string;
    connect: Connect;
    hotelId: string;
}) {
    const row = connect.otaList;
    const differs =
        row !== null &&
        row.propertyCode !== '' &&
        hotelId.trim() !== '' &&
        hotelId.trim() !== row.propertyCode;

    return (
        <Card>
            <CardHeader>
                <CardTitle>From OTA lists</CardTitle>
                <CardDescription>
                    What the owner gave us for {connect.otaName}.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4 text-sm">
                {row === null ? (
                    <p className="text-muted-foreground">Not in OTA lists.</p>
                ) : (
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                        <dt className="text-muted-foreground">Property ID</dt>
                        <dd className="tabular-nums">
                            {row.propertyCode || '(not given)'}
                        </dd>
                        <dt className="text-muted-foreground">Username</dt>
                        <dd className="break-all">{row.username}</dd>
                        <dt className="text-muted-foreground">Password</dt>
                        <dd>
                            <RevealPassword code={code} credentialId={row.id} />
                        </dd>
                        <dt className="text-muted-foreground">Status</dt>
                        <dd>
                            <StatusBadge status={row.status} />
                        </dd>
                    </dl>
                )}

                {row !== null && row.propertyCode === '' && (
                    <Note>
                        Hotel ID missing. Find it in the extranet after signing
                        in.
                    </Note>
                )}
                {differs && row !== null && (
                    <p className="text-muted-foreground">
                        Differs from OTA lists ({row.propertyCode}).
                    </p>
                )}

                <Separator />
                <dl className="flex flex-col gap-1">
                    <dt className="text-muted-foreground">Market manager</dt>
                    <dd>
                        {row?.marketManagerName || row?.marketManagerPhone ? (
                            [row.marketManagerName, row.marketManagerPhone]
                                .filter(Boolean)
                                .join(' · ')
                        ) : (
                            <span className="text-muted-foreground">
                                Not given
                            </span>
                        )}
                    </dd>
                </dl>

                <Separator />
                <dl className="flex flex-col gap-1">
                    <dt className="text-muted-foreground">Owner</dt>
                    <dd>
                        {[connect.ownerName, connect.ownerPhone]
                            .filter(Boolean)
                            .join(' · ') || (
                            <span className="text-muted-foreground">
                                No owner contact
                            </span>
                        )}
                    </dd>
                    {connect.ownerEmail && (
                        <dd className="break-all">{connect.ownerEmail}</dd>
                    )}
                </dl>
                <div className="flex flex-wrap gap-2">
                    {connect.ownerPhone && (
                        <a
                            href={`https://wa.me/${connect.ownerPhone.replace(/\D/g, '')}`}
                            target="_blank"
                            rel="noreferrer"
                            className={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}
                        >
                            <MessageCircle data-icon="inline-start" />
                            WhatsApp
                        </a>
                    )}
                    <Link
                        href={otaLists(code)}
                        className={buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                        })}
                    >
                        Open OTA lists tab
                    </Link>
                </div>
            </CardContent>
        </Card>
    );
}

function Note({ children }: { children: string }) {
    return (
        <p className="flex items-start gap-2">
            <TriangleAlert
                aria-hidden
                className="mt-0.5 size-4 shrink-0 text-warning"
            />
            {children}
        </p>
    );
}

/**
 * The same write-only reveal as the OTA lists tab: one POST that returns the password as JSON, logged, with two-factor.
 */
function RevealPassword({
    code,
    credentialId,
}: {
    code: string;
    credentialId: number;
}) {
    const [password, setPassword] = useState<string | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [copiedText, copy] = useClipboard();
    const reveal = useHttp<Record<string, never>, { password: string }>(
        PropertyOtaListController.reveal({
            property: code,
            credential: credentialId,
        }),
        {},
    );

    if (password !== null) {
        return (
            <span className="flex items-center gap-2">
                <span className="font-mono break-all">{password}</span>
                <Button
                    type="button"
                    size="icon-sm"
                    variant="ghost"
                    aria-label="Copy password"
                    onClick={() => copy(password)}
                >
                    {copiedText === password ? <Check /> : <Copy />}
                </Button>
            </span>
        );
    }

    return (
        <span className="flex flex-col items-start gap-1">
            <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={reveal.processing}
                focusableWhenDisabled
                onClick={() => {
                    setProblem(null);
                    reveal
                        .submit()
                        .then((response) => setPassword(response.password))
                        .catch(() =>
                            setProblem('The password could not be loaded.'),
                        );
                }}
            >
                {reveal.processing && <Spinner data-icon="inline-start" />}
                Reveal
            </Button>
            {problem && (
                <span className="text-xs text-destructive">{problem}</span>
            )}
        </span>
    );
}
