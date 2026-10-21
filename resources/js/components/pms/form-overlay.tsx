import { useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { DiscardChangesDialog } from '@/components/pms/discard-changes-dialog';
import { FormError } from '@/components/pms/form-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FieldGroup } from '@/components/ui/field';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

type Shape =
    | { variant: 'dialog'; size?: 'sm' | 'md' | 'lg' }
    | { variant: 'sheet'; size?: 'md' | 'lg' };

type Props = Shape & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    /** The form's errors. `errors.form` shows as an alert at the top. */
    errors: Partial<Record<string, string>>;
    /** Sheet only: a dirty Sheet asks before it closes. */
    dirty?: boolean;
    /** The title of the discard confirm, for example "Discard this unit?". */
    discardTitle?: string;
    onSubmit: () => void;
    /** A verb and an object, for example "Record payment". */
    submitLabel: ReactNode;
    processing: boolean;
    submitDisabled?: boolean;
    /** `destructive` for a form that undoes something, such as reversing an allocation. */
    submitVariant?: 'default' | 'destructive';
    /** Replaces Cancel and the primary button, for a form with more than one step. */
    footer?: ReactNode;
    /** Shows skeleton fields while an optional prop loads. */
    loading?: boolean;
    /** The fields only. */
    children: ReactNode;
};

/*
 * Tailwind has no 400 or 480 px steps, so these use the spacing scale (100 = 25rem, 120 = 30rem). Under 768 px a
 * Dialog is full width and anchored to the bottom, and a Sheet is full screen.
 */
const DIALOG_SIZE = {
    sm: 'md:max-w-100',
    md: 'md:max-w-lg',
    // 220 = 55rem = 880 px: the check-out Dialog only (two columns from 1024 px).
    lg: 'md:max-w-220',
} as const;

const SHEET_SIZE = {
    md: 'data-[side=right]:md:max-w-120',
    lg: 'data-[side=right]:md:max-w-160',
} as const;

function LoadingFields() {
    return (
        <div className="flex flex-col gap-4" aria-busy="true">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
        </div>
    );
}

/**
 * The Dialog and Sheet shell for a form: header, scrolling fields, footer, the discard confirm for a dirty Sheet and
 * the mobile layout. The page keeps `useForm`, the route and what happens on success. See form containers in the
 * design system.
 */
export function FormOverlay(props: Props) {
    const {
        open,
        onOpenChange,
        title,
        description,
        errors,
        dirty = false,
        discardTitle = 'Discard changes?',
        onSubmit,
        submitLabel,
        processing,
        submitDisabled = false,
        submitVariant = 'default',
        loading = false,
        footer,
        children,
    } = props;
    const formRef = useRef<HTMLFormElement>(null);
    const [confirmingDiscard, setConfirmingDiscard] = useState(false);
    const isSheet = props.variant === 'sheet';

    useEffect(() => {
        if (Object.keys(errors).length > 0) {
            formRef.current
                ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus();
        }
    }, [errors]);

    const requestClose = (next: boolean) => {
        if (!next && isSheet && dirty) {
            setConfirmingDiscard(true);
        } else {
            onOpenChange(next);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit();
    };

    const primary = (
        <Button
            type="submit"
            variant={submitVariant}
            disabled={processing || loading || submitDisabled}
            focusableWhenDisabled
        >
            {processing && <Spinner data-icon="inline-start" />}
            {submitLabel}
        </Button>
    );

    const fields = (
        <FieldGroup>
            <FormError errors={errors} />
            {loading ? <LoadingFields /> : children}
        </FieldGroup>
    );

    if (props.variant === 'sheet') {
        return (
            <>
                <Sheet open={open} onOpenChange={requestClose}>
                    <SheetContent
                        className={cn(
                            'data-[side=right]:w-full data-[side=right]:sm:max-w-none',
                            SHEET_SIZE[props.size ?? 'md'],
                        )}
                    >
                        <SheetHeader>
                            <SheetTitle>{title}</SheetTitle>
                            {description && (
                                <SheetDescription>
                                    {description}
                                </SheetDescription>
                            )}
                        </SheetHeader>
                        <form
                            ref={formRef}
                            onSubmit={submit}
                            className="flex min-h-0 flex-1 flex-col"
                        >
                            <div className="flex-1 overflow-y-auto px-4">
                                {fields}
                            </div>
                            <SheetFooter className="flex-row justify-end border-t bg-muted/50">
                                {footer ?? (
                                    <>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => requestClose(false)}
                                        >
                                            Cancel
                                        </Button>
                                        {primary}
                                    </>
                                )}
                            </SheetFooter>
                        </form>
                    </SheetContent>
                </Sheet>

                <DiscardChangesDialog
                    open={confirmingDiscard}
                    onOpenChange={setConfirmingDiscard}
                    title={discardTitle}
                    onDiscard={() => {
                        setConfirmingDiscard(false);
                        onOpenChange(false);
                    }}
                />
            </>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className={cn(
                    'max-w-full max-md:top-auto max-md:bottom-0 max-md:translate-y-0 max-md:rounded-b-none sm:max-w-none',
                    DIALOG_SIZE[props.size ?? 'md'],
                )}
            >
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                </DialogHeader>
                <form
                    ref={formRef}
                    onSubmit={submit}
                    className="flex flex-col gap-4"
                >
                    {fields}
                    <DialogFooter>
                        {footer ?? (
                            <>
                                <DialogClose
                                    render={<Button variant="outline" />}
                                >
                                    Cancel
                                </DialogClose>
                                {primary}
                            </>
                        )}
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
