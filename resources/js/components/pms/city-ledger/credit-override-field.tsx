import { AlertCircleIcon } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';

/**
 * The answer to a credit limit refusal. Staff who may override see a reason field and can try again; everyone else
 * sees the refusal as an alert. Nothing shows until the server refuses.
 */
export function CreditOverrideField({
    id,
    error,
    canOverride,
    value,
    onChange,
}: {
    id: string;
    error?: string;
    canOverride: boolean;
    value: string;
    onChange: (value: string) => void;
}) {
    if (!error) {
        return null;
    }

    if (!canOverride) {
        return (
            <Alert variant="destructive">
                <AlertCircleIcon />
                <AlertDescription>
                    {error} Ask someone who may override the credit limit.
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Field data-invalid>
            <FieldLabel htmlFor={id}>
                Reason for going over the limit
            </FieldLabel>
            <Textarea
                id={id}
                name="credit_override_reason"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                maxLength={500}
                rows={2}
                aria-invalid
            />
            <FieldError>{error} Enter a reason to continue anyway.</FieldError>
        </Field>
    );
}
