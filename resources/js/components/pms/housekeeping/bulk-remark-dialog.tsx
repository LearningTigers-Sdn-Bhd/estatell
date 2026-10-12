import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { bulk } from '@/routes/housekeeping';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    roomIds: number[];
    /** Called after the remark was added, so the page can clear its selection. */
    onDone: () => void;
};

function BulkRemarkForm({ open, onOpenChange, roomIds, onDone }: Props) {
    const form = useForm({
        room_ids: roomIds,
        action: 'add_remark',
        remark: '',
    });
    const { data, errors } = form;

    const submit = () => {
        form.post(bulk.url(), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                onDone();
            },
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title="Add remark"
            description={`${roomIds.length} ${roomIds.length === 1 ? 'room' : 'rooms'} selected`}
            errors={errors}
            onSubmit={submit}
            submitLabel="Add remark"
            processing={form.processing}
            submitDisabled={data.remark.trim() === ''}
        >
            <Field data-invalid={!!errors.remark}>
                <FieldLabel htmlFor="bulk-remark">Remark</FieldLabel>
                <Input
                    id="bulk-remark"
                    value={data.remark}
                    maxLength={500}
                    autoFocus
                    aria-invalid={!!errors.remark}
                    onChange={(event) =>
                        form.setData('remark', event.target.value)
                    }
                />
                <FieldDescription>
                    Added on a new line after any remark the room already has.
                </FieldDescription>
                <FieldError>{errors.remark}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Adds one remark to every ticked room. The form starts empty each time it opens.
 */
export function BulkRemarkDialog(props: Props) {
    return <BulkRemarkForm key={useFreshOnOpen(props.open)} {...props} />;
}
