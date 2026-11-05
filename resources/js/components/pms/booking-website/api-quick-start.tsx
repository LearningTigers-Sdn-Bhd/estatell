import { ExternalLink, Rocket } from 'lucide-react';
import { SectionHeader } from '@/components/pms/booking-website/section-header';
import { buttonVariants } from '@/components/ui/button';

type Step = { title: string; body: string };

const STEPS: Step[] = [
    {
        title: 'Set up this page',
        body: 'Create the token and add a return page. Give your developer the token and the API address.',
    },
    {
        title: 'Test on the demo',
        body: 'Your developer tests on the demo first. The full guide has the test details.',
    },
    {
        title: 'Keep it safe',
        body: 'Keep the token on your web server, never in the browser.',
    },
    {
        title: 'Go live',
        body: 'Make one real booking and one refund before you open to guests.',
    },
];

/**
 * The developer quick start in the API tab's rail, with a link to the full guide in estatell-docs. It names only the
 * steps the main panel does not show.
 */
export function ApiQuickStart({ docsUrl }: { docsUrl: string }) {
    return (
        <div className="flex flex-col gap-4">
            <SectionHeader
                title="Developer quick start"
                icon={Rocket}
                description="What your website developer needs to start."
            />
            <ol className="flex flex-col gap-3">
                {STEPS.map((step, index) => (
                    <li key={step.title} className="flex gap-3 text-sm">
                        <span className="flex w-8 shrink-0 justify-center">
                            <span className="flex size-6 items-center justify-center rounded-full bg-muted text-xs font-medium tabular-nums">
                                {index + 1}
                            </span>
                        </span>
                        <span className="flex min-w-0 flex-col gap-0.5">
                            <span className="font-medium">{step.title}</span>
                            <span className="text-balance break-words text-muted-foreground">
                                {step.body}
                            </span>
                        </span>
                    </li>
                ))}
            </ol>
            <a
                href={docsUrl}
                target="_blank"
                rel="noopener noreferrer"
                className={buttonVariants({ className: 'w-full' })}
            >
                Full guide
                <ExternalLink aria-hidden data-icon="inline-end" />
                <span className="sr-only">(opens in a new tab)</span>
            </a>
        </div>
    );
}
