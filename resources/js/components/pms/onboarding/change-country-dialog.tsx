import { Fragment } from 'react';
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
import { Spinner } from '@/components/ui/spinner';

type Counts = App.Data.Property.CountryChangeCountsData;

const LINES: { key: keyof Counts; label: string }[] = [
    { key: 'taxes', label: 'Taxes' },
    { key: 'paymentMethods', label: 'Payment methods' },
    { key: 'chargeItems', label: 'Charge items' },
    { key: 'discounts', label: 'Discounts' },
    { key: 'prices', label: 'Rate prices' },
    { key: 'inclusions', label: 'Rate plan inclusions' },
    { key: 'termsDocuments', label: 'Terms documents' },
    { key: 'skippedSteps', label: 'Skipped answers' },
];

/**
 * Confirms a country change once setup started. The counts come from the server; the dialog only lists them.
 */
export function ChangeCountryDialog({
    open,
    onOpenChange,
    countryName,
    counts,
    saving,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    countryName: string;
    counts: Counts;
    saving: boolean;
    onConfirm: () => void;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Change country to {countryName}?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        These are reset to the new country. Room types, rooms,
                        rate plans and team stay.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <dl className="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-sm">
                    {LINES.map((line) => (
                        <Fragment key={line.key}>
                            <dt className="text-muted-foreground">
                                {line.label}
                            </dt>
                            <dd className="text-right font-medium tabular-nums">
                                {counts[line.key]}
                            </dd>
                        </Fragment>
                    ))}
                </dl>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={saving}
                        focusableWhenDisabled
                        onClick={onConfirm}
                    >
                        {saving && <Spinner data-icon="inline-start" />}
                        Change country
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
