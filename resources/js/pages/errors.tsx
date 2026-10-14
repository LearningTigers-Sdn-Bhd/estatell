import { Head, Link } from '@inertiajs/react';
import { Button, buttonVariants } from '@/components/ui/button';
import { redirect as homeRedirect } from '@/routes/home';

type ErrorStatus = 403 | 404 | 419 | 500 | 503;

type Props = {
    status: ErrorStatus;
    recoveryUrl: string;
};

const messages = {
    403: {
        title: 'Access denied',
        description: 'You do not have permission to open this page.',
    },
    404: {
        title: 'Page not found',
        description: 'We could not find the page you requested.',
    },
    419: {
        title: 'Page expired',
        description: 'Refresh this page, then try again.',
    },
    500: {
        title: 'Something went wrong',
        description:
            'We could not complete your request. Please try again later.',
    },
    503: {
        title: 'Maintenance in progress',
        description:
            'Estatell is temporarily unavailable. Please check back soon.',
    },
} satisfies Record<ErrorStatus, { title: string; description: string }>;

export default function ErrorPage({ status, recoveryUrl }: Props) {
    const { title, description } = messages[status];

    function recover() {
        window.location.assign(recoveryUrl);
    }

    return (
        <>
            <Head title={title} />

            <main className="flex min-h-svh flex-col items-center justify-center gap-8 bg-background px-6 py-12 text-center font-sans text-foreground">
                <span
                    role="img"
                    aria-label="Estatell"
                    className="h-6 w-28 shrink-0 bg-contain bg-center bg-no-repeat"
                    style={{ backgroundImage: 'var(--error-wordmark)' }}
                />

                <img
                    src={`/images/errors/${status}.webp`}
                    alt=""
                    width={600}
                    height={450}
                    className="h-48 w-64 max-w-full object-contain"
                />

                <div className="flex w-full max-w-sm flex-col items-center gap-3">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground">
                        ERROR {status}
                    </p>
                    <h1 className="font-heading text-xl font-semibold tracking-tight">
                        {title}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>

                    <div className="mt-3 flex flex-wrap justify-center gap-3">
                        {status === 419 || status === 503 ? (
                            <Button onClick={recover}>
                                {status === 419 ? 'Refresh' : 'Try again'}
                            </Button>
                        ) : (
                            <>
                                <Link
                                    href={homeRedirect()}
                                    className={buttonVariants()}
                                >
                                    Go home
                                </Link>
                                {(status === 403 || status === 404) && (
                                    <Button variant="outline" onClick={recover}>
                                        Go back
                                    </Button>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </main>
        </>
    );
}
