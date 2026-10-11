import { Link, Head, router, useHttp } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { PageHeader } from '@/components/pms/page-header';
import { PostingPreview } from '@/components/pms/room-revenue/posting-preview';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { RoomRevenueTabs } from '@/components/pms/room-revenue/room-revenue-tabs';
import { taxOptions } from '@/components/pms/room-revenue/tax-options';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    FieldDescription,
    FieldError,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import {
    index,
    preview as previewRoute,
    update,
} from '@/routes/settings/room-revenue';
import { index as taxesIndex } from '@/routes/settings/taxes';

type Tax = App.Data.Catalogues.ChargeItemTaxData;
type Preview = App.Data.Catalogues.RoomRevenuePreviewData;
type Selection = { tax_ids: number[] };

export default function RoomRevenue({
    taxes,
    taxIds,
    preview: savedPreview,
    currency,
    hasDayUse,
}: {
    taxes: Tax[];
    taxIds: number[];
    preview: Preview;
    currency: App.Enums.Currency;
    hasDayUse: boolean;
}) {
    const http = useHttp<Selection, Preview>({ tax_ids: taxIds });
    const guard = useUnsavedChanges(http.isDirty);
    const [preview, setPreview] = useState(savedPreview);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const selected = http.data.tax_ids;
    const key = JSON.stringify(
        [...selected].sort((first, second) => first - second),
    );
    const previewedKey = useRef(key);
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });
    const offTaxes = taxes.filter(
        (tax) => !tax.enabled && selected.includes(tax.id),
    );

    useEffect(() => {
        if (previewedKey.current === key) {
            return;
        }

        const timer = window.setTimeout(() => {
            previewedKey.current = key;
            http.post(previewRoute.url(), {
                onSuccess: (response) => setPreview(response),
            }).catch(() => undefined);
        }, 250);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    const save = (onSaved?: () => void) => {
        router.put(update.url(), http.data, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => {
                setErrors({});
                http.setDefaults();
                onSaved?.();
            },
            onError: (next) => {
                setErrors(next);
                guard.stay();
            },
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        save();
    };

    return (
        <>
            <Head title="Room revenue" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Room revenue"
                    description="How room nights and stay changes are taxed and charged."
                />

                <RoomRevenueTabs activeTab="taxes" hasDayUse={hasDayUse}>
                    {taxes.length === 0 ? (
                        <EmptyState
                            icon={Landmark}
                            title="No taxes yet"
                            description="Add a tax first, then choose it here."
                            action={
                                <Link
                                    href={taxesIndex()}
                                    className={buttonVariants()}
                                >
                                    Go to taxes
                                </Link>
                            }
                        />
                    ) : (
                        <form onSubmit={submit} className="flex flex-col gap-6">
                            <div className="flex flex-col gap-8">
                                <FieldSet className="min-w-0">
                                    <FieldLegend variant="label">
                                        Taxes on room revenue
                                    </FieldLegend>
                                    <FieldDescription>
                                        These taxes apply to every room night,
                                        and to no-show, cancellation, late
                                        checkout and early departure charges.
                                    </FieldDescription>
                                    <MultiCombobox
                                        id="room-revenue-taxes"
                                        name="tax_ids"
                                        items={taxOptions(taxes, money)}
                                        value={selected.map(String)}
                                        onValueChange={(value) =>
                                            http.setData(
                                                'tax_ids',
                                                value.map(Number),
                                            )
                                        }
                                        placeholder="No taxes"
                                        emptyLabel="No matching taxes."
                                        invalid={!!errors.tax_ids}
                                    />
                                    <FieldError>
                                        {errors.tax_ids ?? errors['tax_ids.0']}
                                    </FieldError>
                                </FieldSet>

                                <PostingPreview
                                    title="Posting preview"
                                    sample="A room night"
                                    revenueLabel="Room revenue"
                                    preview={preview}
                                    offTaxes={offTaxes}
                                    money={money}
                                    loading={http.processing}
                                />
                            </div>
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    disabled={saving || !http.isDirty}
                                    focusableWhenDisabled
                                >
                                    {saving && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Save taxes
                                </Button>
                            </div>
                        </form>
                    )}
                </RoomRevenueTabs>
            </div>

            <UnsavedChangesDialog
                guard={guard}
                name="room revenue taxes"
                saving={saving}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}

RoomRevenue.layout = {
    breadcrumbs: [{ title: 'Room revenue', href: index() }],
};
