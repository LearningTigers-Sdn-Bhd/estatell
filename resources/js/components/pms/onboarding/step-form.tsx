import { Fieldset } from '@base-ui/react/fieldset';
import { Link } from '@inertiajs/react';
import { AlertCircleIcon, LockIcon } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { buttonVariants } from '@/components/ui/button';

/**
 * The form of one wizard step. A locked step renders the same fields as a preview: the fields sit in a disabled
 * fieldset, under a banner that names the step to finish first. The server still rejects a save while a step is locked,
 * and its message `errors.onboarding` shows at the top.
 *
 * The fieldset is Base UI's Fieldset. Native disabling covers the buttons and inputs that Select, Switch and Combobox
 * render. Base UI reads disabled state from a Field root, not from a fieldset, so those components do not add their own
 * disabled attributes.
 */
export function StepForm({
    id,
    locked = false,
    blockedBy = null,
    error = null,
    onSubmit,
    children,
}: {
    id: string;
    locked?: boolean;
    blockedBy?: { label: string; url: string | null } | null;
    error?: string | null;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    children: ReactNode;
}) {
    return (
        <form id={id} onSubmit={onSubmit} className="flex flex-col gap-6">
            {locked && blockedBy !== null && (
                <Alert>
                    <LockIcon />
                    <AlertTitle>Preview</AlertTitle>
                    <AlertDescription className="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span>
                            Preview. Finish {blockedBy.label} first to fill this
                            in.
                        </span>
                        {blockedBy.url !== null && (
                            <Link
                                href={blockedBy.url}
                                className={buttonVariants({
                                    variant: 'link',
                                    size: 'sm',
                                    className: 'h-auto p-0',
                                })}
                            >
                                Go to {blockedBy.label} →
                            </Link>
                        )}
                    </AlertDescription>
                </Alert>
            )}
            {error !== null && (
                <Alert variant="destructive">
                    <AlertCircleIcon />
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}
            <Fieldset.Root disabled={locked} className="flex flex-col gap-6">
                {children}
            </Fieldset.Root>
        </form>
    );
}
