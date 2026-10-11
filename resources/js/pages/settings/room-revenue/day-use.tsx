import { Head, router, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { PageHeader } from '@/components/pms/page-header';
import { PostingPreview } from '@/components/pms/room-revenue/posting-preview';
import { RoomRevenueTabs } from '@/components/pms/room-revenue/room-revenue-tabs';
import { taxOptions } from '@/components/pms/room-revenue/tax-options';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import {
    FieldDescription,
    FieldError,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { index } from '@/routes/settings/room-revenue';
import {
    preview as previewRoute,
    update,
} from '@/routes/settings/room-revenue/day-use';

type Tax = App.Data.Catalogues.ChargeItemTaxData;
type Preview = App.Data.Catalogues.RoomRevenuePreviewData;
type Selection = { tax_ids: number[] };

export default function RoomRevenueDayUse({
    taxes,
    taxIds,
    preview: savedPreview,
    currency,
}: {
    taxes: Tax[];
    taxIds: number[];
    preview: Preview;
    currency: App.Enums.Currency;
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
            <Head title="Room revenue: Day use" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Room revenue"
                    description="How room nights and stay changes are taxed and charged."
                />

                <RoomRevenueTabs activeTab="day-use" hasDayUse>
                    <form onSubmit={submit} className="flex flex-col gap-6">
                        <div className="flex flex-col gap-8">
                            <FieldSet className="min-w-0">
                                <FieldLegend variant="label">
                                    Taxes on day use
                                </FieldLegend>
                                <FieldDescription>
                                    Day use keeps its own taxes. It does not
                                    follow the Taxes tab, because a per-night
                                    tax such as the Tourism tax does not fit an
                                    hourly stay.
                                </FieldDescription>
                                <MultiCombobox
                                    id="day-use-taxes"
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
                                sample="A day use"
                                revenueLabel="Day use"
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
                                {saving && <Spinner data-icon="inline-start" />}
                                Save taxes
                            </Button>
                        </div>
                    </form>
                </RoomRevenueTabs>
            </div>

            <UnsavedChangesDialog
                guard={guard}
                name="day use taxes"
                saving={saving}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}

RoomRevenueDayUse.layout = {
    breadcrumbs: [{ title: 'Room revenue', href: index() }],
};
