import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    store as storeReason,
    update as updateReason,
} from '@/routes/settings/pos/void-reasons';

export type VoidReasonRow = {
    id: number;
    label: string;
    requires_manager_pin: boolean;
    default_return_stock: boolean;
    active: boolean;
};

export function PosVoidReasonForm({
    row,
    onClose,
}: {
    row: VoidReasonRow | null;
    onClose: () => void;
}) {
    const form = useForm({
        label: row?.label ?? '',
        requires_manager_pin: row?.requires_manager_pin ?? true,
        default_return_stock: row?.default_return_stock ?? false,
        active: row?.active ?? true,
        sort_order: 0,
    });

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(value) => !value && onClose()}
            title={row ? 'Edit void reason' : 'Add void reason'}
            errors={form.errors}
            processing={form.processing}
            submitLabel="Save reason"
            onSubmit={() => {
                const options = { preserveScroll: true, onSuccess: onClose };

                if (row) {
                    form.put(updateReason.url({ reason: row.id }), options);
                } else {
                    form.post(storeReason.url(), options);
                }
            }}
        >
            <Field data-invalid={!!form.errors.label}>
                <FieldLabel htmlFor="reason-label">Reason</FieldLabel>
                <Input
                    id="reason-label"
                    maxLength={80}
                    value={form.data.label}
                    onChange={(event) =>
                        form.setData('label', event.target.value)
                    }
                    autoFocus
                />
                <FieldError>{form.errors.label}</FieldError>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="reason-pin"
                    checked={form.data.requires_manager_pin}
                    onCheckedChange={(checked) =>
                        form.setData('requires_manager_pin', checked)
                    }
                />
                <FieldLabel htmlFor="reason-pin">Needs a manager</FieldLabel>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="reason-stock"
                    checked={form.data.default_return_stock}
                    onCheckedChange={(checked) =>
                        form.setData('default_return_stock', checked)
                    }
                />
                <FieldLabel htmlFor="reason-stock">
                    Put the stock back by default
                </FieldLabel>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="reason-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldLabel htmlFor="reason-active">Active</FieldLabel>
            </Field>
        </FormOverlay>
    );
}
