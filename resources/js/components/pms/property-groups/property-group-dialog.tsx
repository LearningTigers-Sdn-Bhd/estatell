import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store, update } from '@/routes/admin/property-groups';

type Group = App.Data.Platform.PropertyGroupData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The group to rename, or null to create one. */
    group: Group | null;
};

function PropertyGroupForm({ open, onOpenChange, group }: Props) {
    const form = useForm({ name: group?.name ?? '' });
    const { data, errors } = form;

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (group) {
            form.put(update.url({ propertyGroup: group.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={group ? `Rename ${group.name}` : 'New property group'}
            description="Properties run by one business. Their staff can be given access to each other's properties."
            errors={errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={group ? 'Save name' : 'Create group'}
            processing={form.processing}
        >
            <Field data-invalid={!!errors.name}>
                <FieldLabel htmlFor="property-group-name">Name</FieldLabel>
                <Input
                    id="property-group-name"
                    name="name"
                    placeholder="e.g. Tanini"
                    maxLength={100}
                    value={data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!errors.name}
                    autoFocus
                />
                <FieldError>{errors.name}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/** Creates or renames one group of Admin › Property groups. */
export function PropertyGroupDialog(props: Props) {
    return (
        <PropertyGroupForm
            key={`${useFreshOnOpen(props.open)}-${props.group?.id ?? 'new'}`}
            {...props}
        />
    );
}
