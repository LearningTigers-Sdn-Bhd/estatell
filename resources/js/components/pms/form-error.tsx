import { AlertCircleIcon } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { toast } from '@/components/ui/toast';

/**
 * Makes a form-wide refusal visible when the submit button is far from the banner: an error toast and a scroll to
 * the banner. Returns true when there was one.
 */
export function announceFormError(
    errors: Partial<Record<string, string>>,
): boolean {
    const message = errors.form;

    if (!message) {
        return false;
    }

    toast.add({ title: message, type: 'error' });
    document
        .querySelector('[data-form-error]')
        ?.scrollIntoView({ block: 'center', behavior: 'smooth' });

    return true;
}

/**
 * A refusal that belongs to the whole form, not to one field. The server sends it as `errors.form`.
 */
export function FormError({
    errors,
}: {
    errors: Partial<Record<string, string>>;
}) {
    const message = errors.form;

    if (!message) {
        return null;
    }

    return (
        <Alert variant="destructive" data-form-error="">
            <AlertCircleIcon />
            <AlertDescription>{message}</AlertDescription>
        </Alert>
    );
}
