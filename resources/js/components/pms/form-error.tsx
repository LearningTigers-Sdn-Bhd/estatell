import { AlertCircleIcon } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

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
        <Alert variant="destructive">
            <AlertCircleIcon />
            <AlertDescription>{message}</AlertDescription>
        </Alert>
    );
}
