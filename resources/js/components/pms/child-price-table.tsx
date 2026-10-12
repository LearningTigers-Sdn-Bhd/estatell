import { EnumSelect } from '@/components/pms/enum-select';
import { FieldError } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type Option = { value: string; label: string };

/** One age group of the property, as the table shows it. */
export type ChildPriceGroup = {
    ageGroupId: number;
    label: string;
    minAge: number;
    maxAge: number;
};

/** The editable values of one age group. Same order as `groups`. */
export type ChildPriceRow = { pricing: string; value: string };

/**
 * One row per age group: Free, a percent or a fixed amount. Shared by the rate plan Children tab and the charge item
 * Children section. The parent owns the form state and passes `onChange` for one row.
 */
export function ChildPriceTable({
    groups,
    rows,
    pricings,
    currency,
    errors,
    errorPrefix,
    percentDisabled = false,
    onChange,
}: {
    groups: ChildPriceGroup[];
    rows: ChildPriceRow[];
    pricings: Option[];
    currency: App.Enums.Currency;
    errors: Record<string, string | undefined>;
    /** The form key of the list, for example `prices`. Row errors are `${errorPrefix}.${index}.pricing`. */
    errorPrefix: string;
    /** Hides the Percent choice, for a price that has no amount to take a percentage of. */
    percentDisabled?: boolean;
    onChange: (index: number, values: Partial<ChildPriceRow>) => void;
}) {
    const options = percentDisabled
        ? pricings.filter((option) => option.value !== 'percent')
        : pricings;

    return (
        <div className="overflow-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Age group</TableHead>
                        <TableHead>Price</TableHead>
                        <TableHead>Value</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {groups.map((group, index) => {
                        const row = rows[index];
                        const pricingError =
                            errors[`${errorPrefix}.${index}.pricing`];
                        const valueError =
                            errors[`${errorPrefix}.${index}.value`];

                        return (
                            <TableRow key={group.ageGroupId}>
                                <TableCell className="align-top">
                                    <span className="font-medium">
                                        {group.label}
                                    </span>{' '}
                                    <span className="text-muted-foreground tabular-nums">
                                        {group.minAge}–{group.maxAge}
                                    </span>
                                </TableCell>
                                <TableCell className="align-top">
                                    <EnumSelect
                                        aria-label={`Price for ${group.label}`}
                                        options={options}
                                        value={row.pricing}
                                        onValueChange={(value) =>
                                            onChange(index, {
                                                pricing: value,
                                                value:
                                                    value === 'free'
                                                        ? ''
                                                        : row.value,
                                            })
                                        }
                                        aria-invalid={!!pricingError}
                                    />
                                    <FieldError>{pricingError}</FieldError>
                                </TableCell>
                                <TableCell className="align-top">
                                    {row.pricing !== 'free' && (
                                        <>
                                            <InputGroup className="sm:max-w-48">
                                                {row.pricing === 'fixed' && (
                                                    <InputGroupAddon>
                                                        {currency}
                                                    </InputGroupAddon>
                                                )}
                                                <InputGroupInput
                                                    aria-label={`Value for ${group.label}`}
                                                    type="number"
                                                    min={
                                                        row.pricing ===
                                                        'percent'
                                                            ? '1'
                                                            : '0'
                                                    }
                                                    max={
                                                        row.pricing ===
                                                        'percent'
                                                            ? '100'
                                                            : '9999999999.99'
                                                    }
                                                    step="0.01"
                                                    inputMode="decimal"
                                                    value={row.value}
                                                    onChange={(event) =>
                                                        onChange(index, {
                                                            value: event.target
                                                                .value,
                                                        })
                                                    }
                                                    aria-invalid={!!valueError}
                                                />
                                                {row.pricing === 'percent' && (
                                                    <InputGroupAddon align="inline-end">
                                                        %
                                                    </InputGroupAddon>
                                                )}
                                            </InputGroup>
                                            <FieldError>
                                                {valueError}
                                            </FieldError>
                                        </>
                                    )}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
