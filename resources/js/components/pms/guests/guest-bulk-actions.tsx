import { useForm } from '@inertiajs/react';
import { Ban, ChevronDown, Star, UserX } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { BulkActionBar } from '@/components/pms/bulk-action-bar';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    anonymize,
    blacklist,
    markVip,
    unblacklist,
    unmarkVip,
} from '@/routes/guests/bulk';

type Action = App.Enums.GuestBulkAction;
const routes = {
    'mark-vip': markVip,
    'unmark-vip': unmarkVip,
    blacklist,
    unblacklist,
    anonymize,
};

type Props = {
    selected: Set<string>;
    actions: EnumOption[];
    onSelectionChange: (selected: Set<string>) => void;
    onBusyChange: (busy: boolean) => void;
};

export function GuestBulkActions({
    selected,
    actions,
    onSelectionChange,
    onBusyChange,
}: Props) {
    const [dialog, setDialog] = useState<Action | null>(null);
    const form = useForm({
        guest_ids: [] as number[],
        reason: '',
        confirmation: '',
    });
    const can = (action: Action) =>
        actions.some((option) => option.value === action);
    const label = (action: Action) =>
        actions.find((option) => option.value === action)?.label;

    function close() {
        if (!form.processing) {
            setDialog(null);
            form.resetAndClearErrors();
        }
    }

    function run(action: Action, event?: FormEvent) {
        event?.preventDefault();
        if (
            form.processing ||
            !can(action) ||
            selected.size === 0 ||
            (action === 'anonymize' &&
                form.data.confirmation.trim() !== 'Confirm')
        ) {
            return;
        }

        form.transform((data) => ({
            ...data,
            guest_ids: Array.from(selected, Number),
        }));
        form.post(routes[action].url(), {
            preserveScroll: true,
            onStart: () => onBusyChange(true),
            onSuccess: (page) => {
                const result = (
                    page.flash as
                        | {
                              guestBulkResult?: App.Data.Guests.GuestBulkResultData;
                          }
                        | undefined
                )?.guestBulkResult;
                if (result) {
                    onSelectionChange(new Set(result.skippedIds.map(String)));
                }
                setDialog(null);
                form.resetAndClearErrors();
            },
            onFinish: () => onBusyChange(false),
        });
    }

    const submit = (action: Action) => (
        <Button
            type="submit"
            variant="destructive"
            disabled={
                form.processing ||
                (action === 'anonymize' &&
                    form.data.confirmation.trim() !== 'Confirm')
            }
            focusableWhenDisabled
        >
            {form.processing && <Spinner data-icon="inline-start" />}
            {label(action)}
        </Button>
    );

    return (
        <>
            <FieldError>{form.errors.guest_ids}</FieldError>
            <BulkActionBar
                count={selected.size}
                onClear={() => onSelectionChange(new Set())}
                stackOnMobile
                disabled={form.processing}
            >
                {can('mark-vip') && (
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            render={
                                <Button
                                    variant="outline"
                                    disabled={form.processing}
                                    focusableWhenDisabled
                                />
                            }
                        >
                            <Star data-icon="inline-start" />
                            VIP
                            <ChevronDown data-icon="inline-end" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent side="top" align="end">
                            <DropdownMenuGroup>
                                {(['mark-vip', 'unmark-vip'] as const)
                                    .filter(can)
                                    .map((action) => (
                                        <DropdownMenuItem
                                            key={action}
                                            onClick={() => run(action)}
                                        >
                                            {label(action)}
                                        </DropdownMenuItem>
                                    ))}
                            </DropdownMenuGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
                {can('blacklist') && (
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            render={
                                <Button
                                    variant="outline"
                                    disabled={form.processing}
                                    focusableWhenDisabled
                                />
                            }
                        >
                            <Ban data-icon="inline-start" />
                            Blacklist
                            <ChevronDown data-icon="inline-end" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent side="top" align="end">
                            <DropdownMenuGroup>
                                {(['blacklist', 'unblacklist'] as const)
                                    .filter(can)
                                    .map((action) => (
                                        <DropdownMenuItem
                                            key={action}
                                            onClick={() => {
                                                form.resetAndClearErrors();
                                                setDialog(action);
                                            }}
                                        >
                                            {label(action)}
                                        </DropdownMenuItem>
                                    ))}
                            </DropdownMenuGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
                {can('anonymize') && (
                    <Button
                        variant="destructive"
                        disabled={form.processing}
                        focusableWhenDisabled
                        onClick={() => {
                            form.resetAndClearErrors();
                            setDialog('anonymize');
                        }}
                    >
                        <UserX data-icon="inline-start" />
                        Archive
                    </Button>
                )}
            </BulkActionBar>

            <Dialog
                open={dialog === 'blacklist'}
                onOpenChange={(open) => {
                    if (!open) {
                        close();
                    }
                }}
            >
                <DialogContent showCloseButton={!form.processing}>
                    <DialogHeader>
                        <DialogTitle>
                            Blacklist {selected.size} guests?
                        </DialogTitle>
                        <DialogDescription>
                            The same reason applies to each guest. Guests
                            already blacklisted keep their existing reason.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={(event) => run('blacklist', event)}
                        className="flex flex-col gap-4"
                    >
                        <FieldGroup>
                            <Field data-invalid={!!form.errors.reason}>
                                <FieldLabel htmlFor="bulk-blacklist-reason">
                                    Reason
                                </FieldLabel>
                                <Textarea
                                    id="bulk-blacklist-reason"
                                    name="reason"
                                    required
                                    maxLength={500}
                                    value={form.data.reason}
                                    onChange={(event) =>
                                        form.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={!!form.errors.reason}
                                    disabled={form.processing}
                                    autoFocus
                                />
                                <FieldError>{form.errors.reason}</FieldError>
                            </Field>
                        </FieldGroup>
                        <FieldError>{form.errors.guest_ids}</FieldError>
                        <DialogFooter>
                            <DialogClose
                                render={
                                    <Button
                                        variant="outline"
                                        disabled={form.processing}
                                    />
                                }
                            >
                                Cancel
                            </DialogClose>
                            {submit('blacklist')}
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={dialog === 'unblacklist' || dialog === 'anonymize'}
                onOpenChange={(open) => {
                    if (!open) {
                        close();
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {dialog === 'anonymize'
                                ? `Archive ${selected.size} guests?`
                                : `Remove ${selected.size} guests from the blacklist?`}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {dialog === 'anonymize'
                                ? 'Personal data, notes and ID documents are permanently deleted. Reservation and financial history stays. This cannot be undone. Guests who cannot be archived are skipped.'
                                : 'The blacklist reasons are cleared. Each change is recorded in the activity log. Guests not on the blacklist stay unchanged.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <form
                        onSubmit={(event) =>
                            run(
                                dialog === 'anonymize'
                                    ? 'anonymize'
                                    : 'unblacklist',
                                event,
                            )
                        }
                        className="flex flex-col gap-4"
                    >
                        {dialog === 'anonymize' && (
                            <Field data-invalid={!!form.errors.confirmation}>
                                <FieldLabel htmlFor="bulk-archive-confirm">
                                    Type Confirm to continue
                                </FieldLabel>
                                <Input
                                    id="bulk-archive-confirm"
                                    name="confirmation"
                                    autoComplete="off"
                                    placeholder="Confirm"
                                    value={form.data.confirmation}
                                    onChange={(event) =>
                                        form.setData(
                                            'confirmation',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={!!form.errors.confirmation}
                                    disabled={form.processing}
                                />
                                <FieldError>
                                    {form.errors.confirmation}
                                </FieldError>
                            </Field>
                        )}
                        <FieldError>{form.errors.guest_ids}</FieldError>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={form.processing}>
                                Cancel
                            </AlertDialogCancel>
                            {submit(
                                dialog === 'anonymize'
                                    ? 'anonymize'
                                    : 'unblacklist',
                            )}
                        </AlertDialogFooter>
                    </form>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
