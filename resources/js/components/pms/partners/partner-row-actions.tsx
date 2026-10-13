import { Form, router } from '@inertiajs/react';
import { Ellipsis, Pause, Play } from 'lucide-react';
import { useState } from 'react';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { reactivate, suspend } from '@/routes/admin/partners';

type Partner = Pick<App.Data.Platform.AdminPartnerRowData, 'id' | 'name'>;

function RowActionsTrigger({ name }: { name: string }) {
    return (
        <DropdownMenuTrigger
            render={<Button variant="ghost" size="icon-sm" />}
            aria-label={`Actions for ${name}`}
        >
            <Ellipsis />
        </DropdownMenuTrigger>
    );
}

export function ActivePartnerActions({ partner }: { partner: Partner }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <RowActionsTrigger name={partner.name} />
                <DropdownMenuContent align="end" className="w-44">
                    <DropdownMenuItem
                        variant="destructive"
                        onClick={() => setOpen(true)}
                    >
                        <Pause />
                        Suspend
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <AlertDialog open={open} onOpenChange={setOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Suspend {partner.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            They can&apos;t sign in. Their properties keep
                            running.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <Form
                        {...suspend.form({ partner: partner.id })}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setOpen(false)}
                    >
                        {({ processing }) => (
                            <AlertDialogFooter>
                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Suspend
                                </Button>
                            </AlertDialogFooter>
                        )}
                    </Form>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

export function SuspendedPartnerActions({ partner }: { partner: Partner }) {
    return (
        <DropdownMenu>
            <RowActionsTrigger name={partner.name} />
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuItem
                    onClick={() =>
                        router.post(
                            reactivate.url({ partner: partner.id }),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <Play />
                    Reactivate
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
