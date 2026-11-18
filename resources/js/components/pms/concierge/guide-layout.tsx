import { Link } from '@inertiajs/react';
import { Circle, CircleCheck, Pencil } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { StatusBadge } from '@/components/pms/status-badge';
import { buttonVariants } from '@/components/ui/button';
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * A section inside a Workspace panel: the title and description get the normal inset (`ps-3 pt-2`), the content
 * sits on the panel's own padding. `action` sits on the right of the heading.
 */
export function PanelSection({
    title,
    description,
    action,
    className,
    children,
}: {
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
    children?: ReactNode;
}) {
    return (
        <section className={cn('flex flex-col gap-4', className)}>
            <header className="flex items-start justify-between gap-3 ps-3 pt-2">
                <div className="flex min-w-0 flex-col gap-1">
                    <h2 className="text-base font-medium">{title}</h2>
                    {description && (
                        <p className="text-sm text-balance text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {action && <div className="shrink-0">{action}</div>}
            </header>
            {children}
        </section>
    );
}

/** Several sections in one panel, each divided from the one above. */
export function PanelStack({ children }: { children: ReactNode }) {
    return (
        <div className="flex flex-col gap-6 *:not-first:border-t *:not-first:pt-4">
            {children}
        </div>
    );
}

/** Whether a guide section is ready for guests: a green Ready or an amber Add details. */
export function Status({ ready }: { ready: boolean }) {
    return (
        <StatusBadge
            status={
                ready
                    ? { value: 'ready', label: 'Ready', tone: 'success' }
                    : {
                          value: 'missing',
                          label: 'Add details',
                          tone: 'warning',
                      }
            }
            icon={ready ? CircleCheck : Circle}
            className="min-w-26"
        />
    );
}

/**
 * A section edited on another settings page: what guests see now as read-only fields, and a link to change it.
 * Empty fields show "Not set".
 */
export function LinkedSection({
    title,
    body,
    ready,
    fields,
    href,
    action,
}: {
    title: string;
    body: string;
    ready: boolean;
    fields: { label: string; value: string | null }[];
    href: string;
    action: string;
}) {
    const id = useId();

    return (
        <PanelSection
            title={title}
            description={body}
            action={<Status ready={ready} />}
        >
            <FieldGroup className="gap-4">
                {fields.map((field, index) => (
                    <Field key={field.label}>
                        <FieldLabel htmlFor={`${id}-${index}`}>
                            {field.label}
                        </FieldLabel>
                        <Input
                            id={`${id}-${index}`}
                            readOnly
                            value={field.value ?? ''}
                            placeholder="Not set"
                        />
                    </Field>
                ))}
            </FieldGroup>
            <Link
                href={href}
                className={buttonVariants({
                    variant: 'outline',
                    className: 'w-full',
                })}
            >
                <Pencil data-icon="inline-start" />
                {action}
            </Link>
        </PanelSection>
    );
}

/** A titled block of the form, divided from the one above. */
export function FormBlock({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-4 border-t pt-6 first-of-type:border-t-0 first-of-type:pt-0">
            <div className="flex flex-col gap-1">
                <h3 className="text-sm font-semibold">{title}</h3>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            {children}
        </section>
    );
}
