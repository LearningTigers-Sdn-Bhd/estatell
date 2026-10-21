import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { fillSignupMessage } from '@/components/pms/partners/signup-message';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/partner/link/message';

const MAX_LENGTH = 1000;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    partnerName: string;
    signupUrl: string;
    template: string;
    defaultTemplate: string;
};

function SignupMessageForm({
    open,
    onOpenChange,
    partnerName,
    signupUrl,
    template,
    defaultTemplate,
}: Props) {
    const form = useForm({ template });

    const submit = () => {
        form.put(update.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Edit message"
            description='This text is copied when you press "Copy message".'
            errors={form.errors}
            processing={form.processing}
            submitLabel="Save message"
            onSubmit={submit}
        >
            <Field data-invalid={!!form.errors.template}>
                <FieldLabel htmlFor="signup-message-template">
                    Message
                </FieldLabel>
                <Textarea
                    id="signup-message-template"
                    name="template"
                    rows={5}
                    maxLength={MAX_LENGTH}
                    placeholder="e.g. Hi! Join us here: {link}"
                    value={form.data.template}
                    onChange={(event) =>
                        form.setData('template', event.target.value)
                    }
                    aria-invalid={!!form.errors.template}
                    autoFocus
                />
                <FieldDescription>
                    Use {'{partner}'} for your name and {'{link}'} for the
                    signup link. {'{link}'} is required.{' '}
                    <span className="tabular-nums">
                        {form.data.template.length} / {MAX_LENGTH}
                    </span>
                </FieldDescription>
                <FieldError>{form.errors.template}</FieldError>
            </Field>

            <Field>
                <FieldLabel>Preview</FieldLabel>
                <p className="rounded-lg border bg-muted/50 px-2.5 py-2 text-sm whitespace-pre-wrap">
                    {fillSignupMessage(
                        form.data.template,
                        partnerName,
                        signupUrl,
                    )}
                </p>
            </Field>

            <div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={form.data.template === defaultTemplate}
                    onClick={() => form.setData('template', defaultTemplate)}
                >
                    Reset to default
                </Button>
            </div>
        </FormOverlay>
    );
}

/**
 * Edit the text the "Copy message" button copies. The inner form is remounted each time the Dialog opens, so it
 * starts from the saved template with no old errors.
 */
export function SignupMessageDialog(props: Props) {
    const key = useFreshOnOpen(props.open);

    return <SignupMessageForm key={key} {...props} />;
}
