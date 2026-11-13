import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyInput } from '@/components/pms/money-input';
import { MoneyText } from '@/components/pms/money-text';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { store } from '@/routes/reservations/folios/credit-moves';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    folio: App.Data.Folio.FolioData;
    move: App.Data.Folio.CreditMoveData;
    currency: App.Data.Property.CurrencyData | null;
};

type Pick = { on: boolean; amount: string };

/** Cents of a typed amount, for the display totals only. The server checks every amount. */
function cents(amount: string): number {
    const value = Number(amount.replace(/[,\s]/g, ''));

    return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

function asAmount(value: number): string {
    return (value / 100).toFixed(2);
}

function MoveCreditForm({
    open,
    onOpenChange,
    reservationId,
    folio,
    move,
    currency,
}: Props) {
    const [key] = useState(() => crypto.randomUUID());
    const form = useForm<{ picks: Record<number, Pick>; note: string }>({
        picks: Object.fromEntries(
            move.targets.map((target) => [
                target.folioId,
                { on: true, amount: target.owes },
            ]),
        ),
        note: '',
    });
    const { data, errors } = form;
    const fieldErrors = errors as Record<string, string | undefined>;
    const picked = move.targets.filter(
        (target) => data.picks[target.folioId]?.on,
    );
    const moving = picked.reduce(
        (sum, target) => sum + cents(data.picks[target.folioId].amount),
        0,
    );
    const left = cents(move.credit) - moving;
    const overOwed = (target: App.Data.Folio.CreditMoveTargetData) =>
        data.picks[target.folioId].on &&
        cents(data.picks[target.folioId].amount) > cents(target.owes);
    const invalid =
        picked.length === 0 ||
        left < 0 ||
        picked.some(
            (target) =>
                cents(data.picks[target.folioId].amount) <= 0 ||
                overOwed(target),
        );

    const setPick = (folioId: number, change: Partial<Pick>) =>
        form.setData('picks', {
            ...data.picks,
            [folioId]: { ...data.picks[folioId], ...change },
        });

    const submit = () => {
        form.transform((values) => ({
            moves: move.targets
                .filter((target) => values.picks[target.folioId]?.on)
                .map((target) => ({
                    folio_id: target.folioId,
                    amount: values.picks[target.folioId].amount,
                })),
            note: values.note,
            idempotency_key: key,
        }));
        form.post(
            store.url({ reservation: reservationId, folio: Number(folio.key) }),
            {
                preserveScroll: true,
                except: KEEP_OPTIONS,
                onSuccess: () => onOpenChange(false),
            },
        );
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`Move credit from ${folio.name}`}
            description={
                <>
                    {folio.name} holds{' '}
                    <MoneyText amount={move.credit} currency={currency} /> more
                    than its charges. Move it to the folios that still owe.
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel={`Move ${currency ? `${currency.code} ` : ''}${asAmount(moving)}`}
            processing={form.processing}
            submitDisabled={invalid}
        >
            <div className="overflow-hidden rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-10">
                                <span className="sr-only">Move</span>
                            </TableHead>
                            <TableHead>Folio</TableHead>
                            <TableHead className="text-right">Owes</TableHead>
                            <TableHead className="w-36 text-right">
                                Move
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {move.targets.map((target, index) => {
                            const pick = data.picks[target.folioId];
                            const error =
                                fieldErrors[`moves.${index}.amount`] ??
                                (overOwed(target)
                                    ? `More than ${target.label} owes.`
                                    : undefined);

                            return (
                                <TableRow key={target.folioId}>
                                    <TableCell>
                                        <Checkbox
                                            aria-label={`Move credit to ${target.label}`}
                                            checked={pick.on}
                                            onCheckedChange={(on) =>
                                                setPick(target.folioId, {
                                                    on: on === true,
                                                })
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="whitespace-normal">
                                        {target.label}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <MoneyText
                                            amount={target.owes}
                                            currency={currency}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <MoneyInput
                                            aria-label={`Amount for ${target.label}`}
                                            value={pick.amount}
                                            onChange={(value) =>
                                                setPick(target.folioId, {
                                                    amount: value,
                                                })
                                            }
                                            currency={null}
                                            disabled={!pick.on}
                                            invalid={error !== undefined}
                                        />
                                        {error && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {error}
                                            </p>
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
                <dt className="text-muted-foreground">Moving</dt>
                <dd className="text-right font-medium">
                    <MoneyText amount={asAmount(moving)} currency={currency} />
                </dd>
                <dt className="text-muted-foreground">Left on {folio.name}</dt>
                <dd
                    className={
                        left < 0 ? 'text-right text-destructive' : 'text-right'
                    }
                >
                    {left < 0 ? (
                        `More than ${folio.name} holds`
                    ) : (
                        <MoneyText
                            amount={asAmount(left)}
                            currency={currency}
                        />
                    )}
                </dd>
            </dl>
            {fieldErrors.moves && <FieldError>{fieldErrors.moves}</FieldError>}
            <Field data-invalid={!!errors.note}>
                <FieldLabel htmlFor="move-credit-note">
                    Note (optional)
                </FieldLabel>
                <Input
                    id="move-credit-note"
                    value={data.note}
                    maxLength={255}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                    aria-invalid={!!errors.note}
                />
                <FieldError>{errors.note}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Moves an Open folio's credit to the other Open folios of the reservation that owe money. Every folio that owes is
 * ticked and filled with what it owes. The totals are display only: the server checks the credit and each amount.
 */
export function MoveCreditDialog(props: Props) {
    return <MoveCreditForm key={useFreshOnOpen(props.open)} {...props} />;
}
