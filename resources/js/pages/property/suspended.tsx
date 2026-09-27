import { Head, Link } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { buttonVariants } from '@/components/ui/button';
import { logout } from '@/routes';
import { edit as profile } from '@/routes/account/profile';

export default function PropertySuspended() {
    return (
        <>
            <Head title="Property suspended" />

            <div className="flex flex-col items-center gap-6">
                <Link
                    href={profile()}
                    className={buttonVariants({ variant: 'secondary' })}
                >
                    Account settings
                </Link>

                <TextLink href={logout()} className="text-sm">
                    Log out
                </TextLink>
            </div>
        </>
    );
}

PropertySuspended.layout = {
    title: 'Property suspended',
    description:
        'You cannot use this property until a platform admin reactivates it.',
};
