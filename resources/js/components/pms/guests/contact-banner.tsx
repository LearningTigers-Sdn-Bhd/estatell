import { Link } from '@inertiajs/react';
import { Pencil, TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { buttonVariants } from '@/components/ui/button';
import { show } from '@/routes/guests';

/**
 * Asks staff to collect a missing email or phone. Shown only while one is empty. The Details tab is the edit form, so
 * the link shows on the other tabs.
 */
export function ContactBanner({
    guest,
    showEditLink,
}: {
    guest: App.Data.Guests.GuestData;
    showEditLink: boolean;
}) {
    const missing = [!guest.email && 'email', !guest.phone && 'phone'].filter(
        Boolean,
    );

    if (missing.length === 0) {
        return null;
    }

    return (
        <Alert>
            <TriangleAlert className="text-warning" />
            <AlertTitle>
                {missing.length === 2
                    ? 'No email or phone.'
                    : `No ${missing[0]}.`}
            </AlertTitle>
            <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
                <span>Ask the guest at the next stay.</span>
                {showEditLink && (
                    <Link
                        href={show.url({ guest: guest.id })}
                        className={buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                        })}
                    >
                        <Pencil data-icon="inline-start" />
                        Edit details
                    </Link>
                )}
            </AlertDescription>
        </Alert>
    );
}
