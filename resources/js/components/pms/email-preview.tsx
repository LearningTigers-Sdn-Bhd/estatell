import { useState } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

/**
 * A guest email as the guest gets it. The server renders `html` from the same mail template it sends with, so the
 * preview is exact. The frame grows to the email's height and is not clickable (links point to examples). It has no
 * scripts (`sandbox` without `allow-scripts`); `allow-same-origin` only lets it be measured.
 */
export function EmailPreview({
    subject,
    replyTo,
    html,
    loading = false,
    className,
}: {
    subject: string;
    replyTo: string | null;
    html: string;
    loading?: boolean;
    className?: string;
}) {
    const [height, setHeight] = useState(480);

    return (
        <div
            className={cn(
                'flex flex-col overflow-hidden rounded-xl border',
                className,
            )}
        >
            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 border-b bg-muted/40 px-3 py-2 text-sm">
                <dt className="text-muted-foreground">Subject</dt>
                <dd className="min-w-0 font-medium break-words">{subject}</dd>
                <dt className="text-muted-foreground">Replies go to</dt>
                <dd className="min-w-0 break-words">
                    {replyTo ?? (
                        <span className="text-muted-foreground">
                            No contact email set for the property
                        </span>
                    )}
                </dd>
            </dl>
            <div className="relative">
                <iframe
                    title={`Preview of the email: ${subject}`}
                    srcDoc={html}
                    sandbox="allow-same-origin"
                    tabIndex={-1}
                    className={cn(
                        'pointer-events-none block w-full transition-opacity',
                        loading && 'opacity-60',
                    )}
                    style={{ height }}
                    onLoad={(event) => {
                        const document = event.currentTarget.contentDocument;
                        if (document) {
                            setHeight(document.documentElement.scrollHeight);
                        }
                    }}
                />
                {loading && (
                    <Spinner className="absolute top-3 right-3 text-muted-foreground" />
                )}
            </div>
        </div>
    );
}
