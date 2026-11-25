import { Head, useForm } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { edit, update } from '@/routes/account/pos-pin';

export default function PosPin() {
    const form = useForm({ current_password: '', pin: '' });

    return (
        <>
            <Head title="POS PIN" />
            <h1 className="sr-only">POS PIN</h1>
            <div className="flex flex-col gap-6">
                <Heading
                    variant="small"
                    title="POS PIN"
                    description="The PIN you key at the register. Use 4 to 6 digits, 6 if your role approves voids and refunds."
                />
                <form
                    className="flex max-w-sm flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(update.url(), {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <Field data-invalid={!!form.errors.pin}>
                        <FieldLabel htmlFor="pos-pin">New PIN</FieldLabel>
                        <Input
                            id="pos-pin"
                            type="password"
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={12}
                            value={form.data.pin}
                            onChange={(event) =>
                                form.setData(
                                    'pin',
                                    event.target.value.replace(/\D/g, ''),
                                )
                            }
                            aria-invalid={!!form.errors.pin}
                        />
                        <FieldError>{form.errors.pin}</FieldError>
                    </Field>
                    <Field data-invalid={!!form.errors.current_password}>
                        <FieldLabel htmlFor="pos-pin-password">
                            Your password
                        </FieldLabel>
                        <Input
                            id="pos-pin-password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.current_password}
                            onChange={(event) =>
                                form.setData(
                                    'current_password',
                                    event.target.value,
                                )
                            }
                            aria-invalid={!!form.errors.current_password}
                        />
                        <FieldDescription>
                            We ask for it so nobody can set a PIN on your
                            account from an open screen.
                        </FieldDescription>
                        <FieldError>{form.errors.current_password}</FieldError>
                    </Field>
                    <div>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            focusableWhenDisabled
                        >
                            {form.processing && (
                                <Spinner data-icon="inline-start" />
                            )}
                            Save PIN
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

PosPin.layout = {
    breadcrumbs: [{ title: 'POS PIN', href: edit() }],
};
