import { Head } from '@inertiajs/react';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';

type ErrorStatus = 403 | 404 | 419 | 500 | 503;

const messages = {
    403: {
        title: 'We can’t open this page',
        description:
            'Please return to your stay or visit the front desk for help.',
    },
    404: {
        title: 'This page is unavailable',
        description:
            'The link may be out of date. Scan the lobby QR code to start again, or visit the front desk for help.',
    },
    419: {
        title: 'Please open your stay again',
        description:
            'This page has expired. Go back and try again, or scan the lobby QR code to reopen the guest page.',
    },
    500: {
        title: 'We couldn’t load this page',
        description:
            'Please try again later. If you need help with your stay, visit the front desk.',
    },
    503: {
        title: 'Online guest services are unavailable',
        description:
            'Please try again later. The front desk can help you in the meantime.',
    },
} satisfies Record<ErrorStatus, { title: string; description: string }>;

/** Unknown and expired stay links share the same message and expose no booking information. */
export default function ConciergeError({
    status,
    recoveryUrl,
}: {
    status: ErrorStatus;
    recoveryUrl: string | null;
}) {
    const { title, description } = messages[status];

    return (
        <>
            <Head title={title} />
            <div className="concierge-page">
                <header className="border-b border-border/40 pb-5">
                    <p className="text-sm font-medium">Guest concierge</p>
                </header>
                <section
                    className="mx-auto flex w-full max-w-md flex-1 flex-col items-center justify-center gap-6 py-6 text-center"
                    aria-labelledby="guest-error-title"
                >
                    <img
                        src="/images/concierge-host.png"
                        alt=""
                        width={1024}
                        height={1024}
                        className="size-48 object-contain sm:size-56"
                    />
                    <div className="flex flex-col gap-3">
                        <h1
                            id="guest-error-title"
                            className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl"
                        >
                            {title}
                        </h1>
                        <p className="text-sm leading-relaxed text-pretty text-muted-foreground sm:text-base">
                            {description}
                        </p>
                    </div>
                    {recoveryUrl && (
                        <Button
                            size="lg"
                            className="min-h-12 px-5"
                            onClick={() => window.location.assign(recoveryUrl)}
                        >
                            {status === 419 || status === 503 ? (
                                <>
                                    <RefreshCw
                                        data-icon="inline-start"
                                        aria-hidden
                                    />
                                    Try again
                                </>
                            ) : (
                                <>
                                    <ArrowLeft
                                        data-icon="inline-start"
                                        aria-hidden
                                    />
                                    Go back
                                </>
                            )}
                        </Button>
                    )}
                </section>
            </div>
        </>
    );
}
