import { Head, Link } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { buttonVariants } from '@/components/ui/button';
import { logout } from '@/routes';
import { edit as profile } from '@/routes/account/profile';

export default function PortalIndex() {
    return (
        <>
            <Head title="Company portal" />

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

PortalIndex.layout = {
    title: 'The company portal is coming soon',
    description:
        'You can sign in and manage your account. Booking and statements will open here.',
};
