import { Form, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { stop } from '@/routes/impersonation';

/**
 * Shown on every staff page while a support user views the property as one of its staff. It cannot be dismissed. Stop
 * ends the session and returns to the admin or partner page it started from.
 */
export function ImpersonationBanner() {
    const { impersonation } = usePage().props;

    if (impersonation === null) {
        return null;
    }

    const who = impersonation.userRole
        ? `${impersonation.userName} (${impersonation.userRole})`
        : impersonation.userName;

    return (
        <div
            role="status"
            className="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-warning/40 bg-warning/10 px-4 py-2 text-sm"
        >
            <TriangleAlert className="size-4 shrink-0 text-warning" />
            <p className="min-w-0 flex-1 font-medium">
                Viewing as {who} · {impersonation.propertyName} (
                {impersonation.propertyCode})
                {impersonation.partnerName &&
                    ` · via ${impersonation.partnerName}`}{' '}
                · {impersonation.minutes} min
            </p>
            <Form {...stop.form()}>
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="destructive"
                        size="sm"
                        disabled={processing}
                        focusableWhenDisabled
                    >
                        {processing && <Spinner data-icon="inline-start" />}
                        Stop
                    </Button>
                )}
            </Form>
        </div>
    );
}
