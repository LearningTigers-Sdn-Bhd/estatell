import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { store, update } from '@/routes/settings/market-segments';

type Segment = App.Data.Reservations.MarketSegmentData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The segment to edit, or null to add one. */
    segment: Segment | null;
};

function MarketSegmentForm({ open, onOpenChange, segment }: Props) {
    const form = useForm({
        name: segment?.name ?? '',
        active: segment?.active ?? true,
    });
    const { data, errors } = form;

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (segment) {
            form.put(update.url({ marketSegment: segment.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={segment ? `Edit ${segment.name}` : 'Add market segment'}
            description="Staff pick it on a booking to sort bookings by market."
            errors={errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={segment ? 'Save changes' : 'Add segment'}
            processing={form.processing}
        >
            <Field data-invalid={!!errors.name}>
                <FieldLabel htmlFor="market-segment-name">Name</FieldLabel>
                <Input
                    id="market-segment-name"
                    name="name"
                    placeholder="e.g. Leisure"
                    maxLength={50}
                    value={data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!errors.name}
                    autoFocus
                />
                <FieldError>{errors.name}</FieldError>
            </Field>
            {segment && (
                <Field orientation="horizontal">
                    <Switch
                        id="market-segment-active"
                        name="active"
                        checked={data.active}
                        onCheckedChange={(checked) =>
                            form.setData('active', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="market-segment-active">
                            Available
                        </FieldLabel>
                        <FieldDescription>
                            Turn it off to hide it on new bookings. Bookings
                            that have it keep it.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            )}
        </FormOverlay>
    );
}

/** Adds or edits one market segment of Settings › Market segments. */
export function MarketSegmentDialog(props: Props) {
    return (
        <MarketSegmentForm
            key={`${useFreshOnOpen(props.open)}-${props.segment?.id ?? 'new'}`}
            {...props}
        />
    );
}
