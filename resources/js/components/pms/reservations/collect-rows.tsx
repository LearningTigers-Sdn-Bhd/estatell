import { formatMoney, MoneyText } from '@/components/pms/money-text';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
} from '@/components/ui/field';

export type ChargeLine =
    App.Data.Reservations.CheckInCollectionData['charges'][number];

export type TaxLine =
    App.Data.Reservations.CheckInCollectionData['taxes'][number];

/**
 * True when the guest may pay the tax at check-in, so its row has a tick: not waived or exempt, and on the guest's
 * folio or on a Master no company pays (`payableOnMaster`).
 */
export function isCollectable(tax: TaxLine): boolean {
    return (
        tax.waivedBy === null &&
        !tax.exempt &&
        (tax.chargedTo === null || tax.payableOnMaster) &&
        Number(tax.amount) > 0
    );
}

/** Whether a tax row starts ticked: the guest's own taxes yes, a tax paid into the Master folio no (the leader may pay it). */
export function isTickedByDefault(tax: TaxLine): boolean {
    return !tax.payableOnMaster;
}

/**
 * One Collect at check-in tax. A tax the guest pays at check-in has a tick (unticked, it stays on the folio for
 * later). A tax on a Master no company pays has one too, unticked by default, with "On the master folio" under it. Otherwise the row is text: Exempt, who paid it when waived, or where it is charged (the Master folio or its
 * company). Paid elsewhere… shows when the tax can be marked as paid elsewhere.
 */
export function TaxRow({
    tax,
    currency,
    checked,
    onCheckedChange,
    onWaiveTax,
    idPrefix = 'check-in',
}: {
    tax: TaxLine;
    currency: App.Data.Property.CurrencyData | null;
    checked?: boolean;
    onCheckedChange?: (checked: boolean) => void;
    onWaiveTax?: () => void;
    idPrefix?: string;
}) {
    const link = tax.waivable && onWaiveTax !== undefined && (
        <Button
            type="button"
            variant="link"
            size="xs"
            className="self-start px-0"
            onClick={onWaiveTax}
        >
            Paid elsewhere…
        </Button>
    );

    if (
        isCollectable(tax) &&
        checked !== undefined &&
        onCheckedChange !== undefined
    ) {
        return (
            <div className="flex flex-col">
                <CollectRow
                    id={`${idPrefix}-tax-${tax.taxId}`}
                    label={tax.name}
                    amount={tax.amount}
                    currency={currency}
                    checked={checked}
                    onCheckedChange={onCheckedChange}
                    description={tax.chargedTo ?? undefined}
                />
                {link && <div className="flex pl-6">{link}</div>}
            </div>
        );
    }

    return (
        <div className="flex flex-col items-start pl-6">
            <div className="flex w-full justify-between gap-4">
                <span className="text-muted-foreground">{tax.name}</span>
                {tax.waivedBy !== null ? (
                    <span className="text-muted-foreground">
                        {tax.waivedBy}
                    </span>
                ) : tax.exempt ? (
                    <span>Exempt</span>
                ) : tax.chargedTo !== null ? (
                    <span className="text-muted-foreground">
                        {tax.chargedTo}
                    </span>
                ) : (
                    <MoneyText amount={tax.amount} currency={currency} />
                )}
            </div>
            {link}
        </div>
    );
}

/** A tickable line of the To collect list: the label on the left, the amount on the right. */
export function CollectRow({
    id,
    label,
    amount,
    currency,
    checked,
    onCheckedChange,
    description,
    prefix,
}: {
    id: string;
    label: string;
    amount: string;
    currency: App.Data.Property.CurrencyData | null;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    description?: string;
    prefix?: string;
}) {
    return (
        <Field orientation="horizontal">
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={onCheckedChange}
            />
            <FieldContent>
                <FieldLabel htmlFor={id} className="w-full justify-between">
                    <span>{label}</span>
                    <span>
                        {prefix}
                        <MoneyText amount={amount} currency={currency} />
                    </span>
                </FieldLabel>
                {description !== undefined && (
                    <FieldDescription>{description}</FieldDescription>
                )}
            </FieldContent>
        </Field>
    );
}

type Collection = App.Data.Reservations.CheckInCollectionData;

/** The payable charges on one folio (`unit` or `master`), only the ticked ones when `ids` is a list. */
export function payableSum(
    charges: ChargeLine[],
    folio: string,
    ids: number[] | null,
): number {
    return charges
        .filter(
            (charge) =>
                charge.payable &&
                charge.folio === folio &&
                (ids === null || ids.includes(charge.codeId)),
        )
        .reduce((total, charge) => total + Number(charge.amount), 0);
}

/**
 * What the Full bill asks for, display only (the server works it out again): the unit-folio charges less what that
 * folio holds, plus the Master charges up to what the Master still owes. `ticked` limits it to the ticked charges.
 */
export function fullBillAmount(
    collection: Pick<Collection, 'charges' | 'chargesPaid' | 'masterOwed'>,
    ticked: number[] | null,
): { unit: number; master: number } {
    const sum = (folio: string, ids: number[] | null) =>
        payableSum(collection.charges, folio, ids);
    const unitOwed = Math.max(
        0,
        sum('unit', null) - Number(collection.chargesPaid),
    );

    return {
        unit: Math.min(sum('unit', ticked), unitOwed),
        master: Math.min(
            sum('master', ticked),
            Number(collection.masterOwed ?? 0),
        ),
    };
}

/**
 * The Full bill as a short summary: a parent tick (mixed when only some are ticked) with what is still owed, a Room
 * row and a row per other charge, each with its taxes. A charge on the Master or a company's folio says so under it;
 * one the desk cannot take (a company's) has no tick. Unticked by default.
 */
export function ChargeGroup({
    idPrefix,
    charges,
    owed,
    paid,
    ticked,
    onTickedChange,
    currency,
}: {
    idPrefix: string;
    charges: ChargeLine[];
    owed: number;
    paid: number;
    ticked: number[];
    onTickedChange: (ticked: number[]) => void;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const ids = charges
        .filter((charge) => charge.payable)
        .map((charge) => charge.codeId);
    const all = ids.length > 0 && ids.every((id) => ticked.includes(id));
    const some = ids.some((id) => ticked.includes(id));

    return (
        <div className="flex flex-col gap-2">
            <Field orientation="horizontal">
                <Checkbox
                    id={`${idPrefix}-charges`}
                    checked={all}
                    indeterminate={some && !all}
                    disabled={ids.length === 0}
                    onCheckedChange={(checked) =>
                        onTickedChange(checked ? ids : [])
                    }
                />
                <FieldContent>
                    <FieldLabel
                        htmlFor={`${idPrefix}-charges`}
                        className="w-full justify-between"
                    >
                        <span>Full bill</span>
                        <MoneyText amount={String(owed)} currency={currency} />
                    </FieldLabel>
                    {paid > 0 && (
                        <FieldDescription>
                            Still owed after{' '}
                            {formatMoney(String(paid), currency)} paid.
                        </FieldDescription>
                    )}
                </FieldContent>
            </Field>
            <div className="flex flex-col gap-2 pl-6">
                {charges.map((charge) =>
                    charge.payable ? (
                        <CollectRow
                            key={charge.codeId}
                            id={`${idPrefix}-charge-${charge.codeId}`}
                            label={charge.label}
                            amount={charge.amount}
                            currency={currency}
                            checked={ticked.includes(charge.codeId)}
                            onCheckedChange={(checked) =>
                                onTickedChange(
                                    checked
                                        ? [...ticked, charge.codeId]
                                        : ticked.filter(
                                              (id) => id !== charge.codeId,
                                          ),
                                )
                            }
                            description={charge.chargedTo ?? undefined}
                        />
                    ) : (
                        <div key={charge.codeId} className="flex flex-col pl-6">
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    {charge.label}
                                </span>
                                <span className="text-muted-foreground">
                                    <MoneyText
                                        amount={charge.amount}
                                        currency={currency}
                                    />
                                </span>
                            </div>
                            <span className="text-muted-foreground">
                                {charge.chargedTo}
                            </span>
                        </div>
                    ),
                )}
            </div>
        </div>
    );
}
