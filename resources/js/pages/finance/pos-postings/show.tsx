import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { index, resolve, show } from '@/routes/finance/pos-postings';

type Guest = {
    reservation_unit_id: number;
    reservation_reference: string;
    room_number: string;
    guest_name: string;
    arrival_date: string;
    departure_date: string;
    can_post_charges: boolean;
};

type Props = {
    posting: App.Data.Pos.PosPostingDetailData;
    guests?: Guest[];
    q: string | null;
    currency: App.Data.Property.CurrencyData | null;
};

/** A receipt value as text. The payload is the provider's own, so anything else shows as empty. */
function text(value: unknown, fallback = ''): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : fallback;
}

/** What the guest ordered, from the provider's own receipt. */
function Items({ payload }: { payload: Record<string, unknown> }) {
    const items = Array.isArray(payload.items)
        ? (payload.items as Record<string, unknown>[])
        : [];

    if (items.length === 0) {
        return (
            <pre className="overflow-x-auto rounded-lg bg-muted p-3 text-xs">
                {JSON.stringify(payload, null, 2)}
            </pre>
        );
    }

    return (
        <ul className="flex flex-col gap-1 text-sm">
            {items.map((item, position) => (
                <li key={position} className="flex justify-between gap-3">
                    <span>
                        {text(item.qty, '1')} × {text(item.name, 'Item')}
                    </span>
                    <span className="tabular-nums">{text(item.amount)}</span>
                </li>
            ))}
        </ul>
    );
}

export default function PosPostingShow({
    posting,
    guests = [],
    q,
    currency,
}: Props) {
    const [search, setSearch] = useState(q ?? '');
    const form = useForm({
        action: 'attach',
        reservation_unit_id: '',
        folio_id: '',
        note: '',
    });
    const row = posting.row;

    function lookup() {
        router.get(
            show.url(
                { posting: row.id },
                { query: search ? { q: search } : {} },
            ),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function submit(action: 'attach' | 'ignore') {
        form.transform((data) => ({ ...data, action }));
        form.post(resolve.url({ posting: row.id }), { preserveScroll: true });
    }

    return (
        <>
            <Head title={`POS receipt #${row.receiptNumber}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={`Receipt #${row.receiptNumber}`}
                    description={`${row.outletName} · ${row.type}`}
                    back={{ href: index(), label: 'POS postings' }}
                    badge={<StatusBadge status={row.status} />}
                />

                {row.failureReason && (
                    <Alert>
                        <AlertTitle>{row.failureReason}</AlertTitle>
                    </Alert>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Receipt</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <dl className="grid grid-cols-2 gap-2 text-sm">
                                <dt className="text-muted-foreground">
                                    Amount
                                </dt>
                                <dd>
                                    <MoneyText
                                        amount={row.amount}
                                        currency={currency}
                                    />
                                </dd>
                                <dt className="text-muted-foreground">
                                    Reservation
                                </dt>
                                <dd>{row.reference ?? '—'}</dd>
                                <dt className="text-muted-foreground">
                                    Folio line
                                </dt>
                                <dd>{posting.folioLine ?? '—'}</dd>
                                <dt className="text-muted-foreground">
                                    Received
                                </dt>
                                <dd>
                                    <DateText date={row.createdAt} />
                                </dd>
                            </dl>
                            <Items payload={posting.rawPayload} />
                        </CardContent>
                    </Card>

                    {posting.resolvedAt && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Resolved</CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm">
                                <p>
                                    {posting.resolvedBy ?? 'The system'} ·{' '}
                                    <DateText date={posting.resolvedAt} />
                                </p>
                                {posting.resolutionNote && (
                                    <p className="text-muted-foreground">
                                        {posting.resolutionNote}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {posting.canAttach && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Attach to a guest</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <form
                                    className="flex gap-2"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        lookup();
                                    }}
                                >
                                    <Input
                                        aria-label="Find an in-house guest"
                                        placeholder="Room, guest or reservation"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                    />
                                    <Button type="submit" variant="outline">
                                        Find
                                    </Button>
                                </form>
                                {guests.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No in-house guests match.
                                    </p>
                                ) : (
                                    <ul className="flex flex-col gap-2">
                                        {guests.map((guest) => (
                                            <li
                                                key={guest.reservation_unit_id}
                                                className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                                            >
                                                <span>
                                                    <span className="font-medium">
                                                        Room {guest.room_number}
                                                    </span>{' '}
                                                    · {guest.guest_name}
                                                    <br />
                                                    <span className="text-muted-foreground">
                                                        {
                                                            guest.reservation_reference
                                                        }
                                                    </span>
                                                </span>
                                                <Button
                                                    size="sm"
                                                    disabled={
                                                        form.processing ||
                                                        !guest.can_post_charges
                                                    }
                                                    focusableWhenDisabled
                                                    onClick={() => {
                                                        form.setData(
                                                            'reservation_unit_id',
                                                            String(
                                                                guest.reservation_unit_id,
                                                            ),
                                                        );
                                                        form.transform(
                                                            (data) => ({
                                                                ...data,
                                                                action: 'attach',
                                                                reservation_unit_id:
                                                                    String(
                                                                        guest.reservation_unit_id,
                                                                    ),
                                                            }),
                                                        );
                                                        form.post(
                                                            resolve.url({
                                                                posting: row.id,
                                                            }),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }}
                                                >
                                                    {form.processing && (
                                                        <Spinner data-icon="inline-start" />
                                                    )}
                                                    Post to folio
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <FieldError>
                                    {form.errors.reservation_unit_id}
                                </FieldError>
                            </CardContent>
                        </Card>
                    )}

                    {posting.canAttach ||
                    row.status.value === 'pending_review' ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>Ignore this posting</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <Field data-invalid={!!form.errors.note}>
                                    <FieldLabel htmlFor="ignore-note">
                                        Note
                                    </FieldLabel>
                                    <Textarea
                                        id="ignore-note"
                                        maxLength={500}
                                        placeholder="e.g. Guest paid cash at the front desk"
                                        value={form.data.note}
                                        onChange={(event) =>
                                            form.setData(
                                                'note',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!form.errors.note}
                                    />
                                    <FieldError>{form.errors.note}</FieldError>
                                </Field>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="self-start"
                                    disabled={form.processing}
                                    focusableWhenDisabled
                                    onClick={() => submit('ignore')}
                                >
                                    Ignore posting
                                </Button>
                                <AlertDescription className="text-xs text-muted-foreground">
                                    The receipt stays in the log. Nothing is
                                    posted to a folio.
                                </AlertDescription>
                            </CardContent>
                        </Card>
                    ) : null}
                </div>
            </div>
        </>
    );
}

PosPostingShow.layout = {
    breadcrumbs: [{ title: 'POS postings', href: index() }],
};
