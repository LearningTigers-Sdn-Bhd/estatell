import { Check, Copy, ExternalLink, KeyRound } from 'lucide-react';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useClipboard } from '@/hooks/use-clipboard';

type DemoAccess = App.Data.Platform.DemoAccessData;

/**
 * The ready-to-send login for one demo property. It is built here because the partner already sees every part of it.
 */
function loginMessage(
    access: DemoAccess,
    login: App.Data.Platform.DemoLoginData,
): string {
    return [
        `Try estatell on ${login.property}: ${access.url}`,
        `Email: ${login.email}`,
        `Password: ${access.password}`,
        'The demo is shared with other visitors and resets every night.',
    ].join('\n');
}

/**
 * The demo environment link and its Owner logins, for partners to hand to prospects. Partners can't change it.
 */
export function DemoAccessCard({ access }: { access: DemoAccess }) {
    const [copiedText, copy] = useClipboard();

    return (
        <Card>
            <CardContent className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex min-w-0 flex-col gap-1">
                        <h2 className="text-sm font-medium">Demo access</h2>
                        <p className="text-sm text-muted-foreground">
                            Prospects can try two sample properties. The demo is
                            shared and resets every night.
                        </p>
                    </div>
                    <a
                        href={access.url}
                        target="_blank"
                        rel="noreferrer"
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <ExternalLink data-icon="inline-start" />
                        Open demo
                    </a>
                </div>

                <ul className="flex flex-col divide-y rounded-lg border">
                    {access.logins.map((login) => {
                        const message = loginMessage(access, login);

                        return (
                            <li
                                key={login.email}
                                className="flex flex-wrap items-center justify-between gap-3 px-3 py-2"
                            >
                                <div className="flex min-w-0 flex-col">
                                    <span className="text-sm font-medium">
                                        {login.property}
                                    </span>
                                    <span className="truncate text-sm text-muted-foreground">
                                        {login.email}
                                    </span>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => copy(message)}
                                >
                                    {copiedText === message ? (
                                        <Check data-icon="inline-start" />
                                    ) : (
                                        <Copy data-icon="inline-start" />
                                    )}
                                    {copiedText === message
                                        ? 'Copied'
                                        : 'Copy login'}
                                </Button>
                            </li>
                        );
                    })}
                </ul>

                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <KeyRound className="size-4 shrink-0" />
                    Password:{' '}
                    <span className="font-medium text-foreground tabular-nums">
                        {access.password}
                    </span>
                </p>
            </CardContent>
        </Card>
    );
}
