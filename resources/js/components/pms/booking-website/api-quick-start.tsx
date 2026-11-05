import { Copy, ExternalLink } from 'lucide-react';
import { SectionHeader } from '@/components/pms/booking-website/section-header';
import { Button, buttonVariants } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { useClipboard } from '@/hooks/use-clipboard';

type Step = { title: string; body: string };

function steps(apiAddress: string): Step[] {
    return [
        {
            title: 'Get access',
            body: `Your API address is ${apiAddress}. Create the token on this page and send it to your developer. Add the page guests come back to after they pay.`,
        },
        {
            title: 'Test on the demo',
            body: 'Build and test on the demo first. The demo address, the test token and the test card are in the full guide.',
        },
        {
            title: 'Make a booking',
            body: 'Get offers, hold the rooms, start the payment, open Curlec checkout, then read the hold when the guest comes back.',
        },
        {
            title: 'Keep it safe',
            body: 'Keep the token on your web server. Escape all content, use a strict Content Security Policy, and keep other scripts off the payment page.',
        },
        {
            title: 'Go live',
            body: 'Change to this API address and token. Make one real booking and one refund before you open to guests.',
        },
    ];
}

/**
 * The developer quick start in the API tab's rail. The owner copies it as plain text for their developer, who
 * usually has no estatell login. The copied text never holds the token.
 */
export function ApiQuickStart({
    apiAddress,
    docsUrl,
}: {
    apiAddress: string;
    docsUrl: string;
}) {
    const [, copy] = useClipboard();
    const list = steps(apiAddress);

    async function copyGuide() {
        const text = [
            'estatell booking API: quick start',
            ...list.map(
                (step, index) => `${index + 1}. ${step.title}\n${step.body}`,
            ),
            `Full guide: ${docsUrl}`,
        ].join('\n\n');

        if (await copy(text)) {
            toast.add({ title: 'Quick start copied.', type: 'success' });
        }
    }

    return (
        <div className="flex flex-col gap-4">
            <SectionHeader
                title="Developer quick start"
                description="Send this to your website developer."
            />
            <ol className="flex flex-col gap-3">
                {list.map((step, index) => (
                    <li key={step.title} className="flex gap-3 text-sm">
                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium tabular-nums">
                            {index + 1}
                        </span>
                        <span className="flex min-w-0 flex-col gap-0.5">
                            <span className="font-medium">{step.title}</span>
                            <span className="break-words text-muted-foreground">
                                {step.body}
                            </span>
                        </span>
                    </li>
                ))}
            </ol>
            <div className="flex flex-wrap gap-2">
                <Button variant="outline" onClick={copyGuide}>
                    <Copy data-icon="inline-start" />
                    Copy for developer
                </Button>
                <a
                    href={docsUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={buttonVariants({ variant: 'outline' })}
                >
                    Full guide
                    <ExternalLink aria-hidden data-icon="inline-end" />
                    <span className="sr-only">(opens in a new tab)</span>
                </a>
            </div>
        </div>
    );
}
