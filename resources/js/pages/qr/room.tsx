import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { QrMenu } from '@/components/pms/qr/qr-menu';
import type { QrCategory, QrLine } from '@/components/pms/qr/qr-menu';
import { buttonVariants, Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    show as roomShow,
    orders as roomOrders,
    unlock as roomUnlock,
} from '@/routes/qr/room';

type Props = {
    token: string;
    status: 'active' | 'revoked' | 'expired' | 'locked';
    unlocked: boolean;
    property: string;
    currency: App.Data.Property.CurrencyData | null;
    outlets: { id: number; name: string }[];
    outlet: { id: number; name: string } | null;
    allowRemark: boolean;
    categories: QrCategory[];
};

export default function QrRoom({
    token,
    status,
    unlocked,
    property,
    currency,
    outlets,
    outlet,
    allowRemark,
    categories,
}: Props) {
    const unlockForm = useForm({ pin: '', last_name: '' });
    const [sending, setSending] = useState(false);

    if (status !== 'active') {
        return (
            <>
                <Head title={property} />
                <Card className="m-4">
                    <CardHeader>
                        <CardTitle>Room ordering is closed</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {status === 'locked'
                            ? 'Too many wrong tries. Ordering is locked for 15 minutes.'
                            : 'This room code is no longer valid. Please ask the front desk.'}
                    </CardContent>
                </Card>
            </>
        );
    }

    if (!unlocked) {
        return (
            <>
                <Head title={`${property} · Room dining`} />
                <Card className="m-4">
                    <CardHeader>
                        <CardTitle>Room dining</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="flex flex-col gap-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                unlockForm.post(roomUnlock.url({ token }));
                            }}
                        >
                            <p className="text-sm text-muted-foreground">
                                Enter the 4-digit PIN from your welcome card and
                                the last name of the primary guest.
                            </p>
                            <Field data-invalid={!!unlockForm.errors.pin}>
                                <FieldLabel htmlFor="room-pin">
                                    Stay PIN
                                </FieldLabel>
                                <Input
                                    id="room-pin"
                                    type="password"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={4}
                                    value={unlockForm.data.pin}
                                    onChange={(event) =>
                                        unlockForm.setData(
                                            'pin',
                                            event.target.value.replace(
                                                /\D/g,
                                                '',
                                            ),
                                        )
                                    }
                                />
                                <FieldError>
                                    {unlockForm.errors.pin}
                                </FieldError>
                            </Field>
                            <Field
                                data-invalid={!!unlockForm.errors.last_name}
                            >
                                <FieldLabel htmlFor="room-last">
                                    Last name
                                </FieldLabel>
                                <Input
                                    id="room-last"
                                    autoComplete="family-name"
                                    maxLength={80}
                                    value={unlockForm.data.last_name}
                                    onChange={(event) =>
                                        unlockForm.setData(
                                            'last_name',
                                            event.target.value,
                                        )
                                    }
                                />
                                <FieldError>
                                    {unlockForm.errors.last_name}
                                </FieldError>
                            </Field>
                            <Button
                                type="submit"
                                size="lg"
                                className="min-h-11"
                                disabled={unlockForm.processing}
                                focusableWhenDisabled
                            >
                                {unlockForm.processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Continue
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </>
        );
    }

    return (
        <>
            <Head title={`${property} · Room dining`} />
            <nav className="flex gap-2 overflow-x-auto p-4">
                {outlets.map((row) => (
                    <Link
                        key={row.id}
                        href={roomShow.url(
                            { token },
                            { query: { outlet: row.id } },
                        )}
                        className={`${buttonVariants({
                            variant:
                                outlet?.id === row.id ? 'default' : 'outline',
                            size: 'lg',
                        })} min-h-11 shrink-0`}
                    >
                        {row.name}
                    </Link>
                ))}
            </nav>
            {outlet !== null && (
                <QrMenu
                    categories={categories}
                    currency={currency}
                    allowRemark={allowRemark}
                    sending={sending}
                    onSend={(lines: QrLine[], clearCart: () => void) => {
                        setSending(true);
                        router.post(
                            roomOrders.url({ token }),
                            { outlet_id: outlet.id, lines },
                            {
                                onSuccess: clearCart,
                                onFinish: () => setSending(false),
                            },
                        );
                    }}
                />
            )}
        </>
    );
}
