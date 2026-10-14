import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { buttonVariants } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useIsImpersonating } from '@/hooks/use-is-impersonating';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/account/appearance';
import { edit as editNotifications } from '@/routes/account/notifications';
import { edit } from '@/routes/account/profile';
import { edit as editSecurity } from '@/routes/account/security';
import type { NavItem } from '@/types';

const baseSidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
];

export default function AccountLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { property } = usePage().props;
    const impersonating = useIsImpersonating();
    const accountNavItems = impersonating
        ? baseSidebarNavItems.filter((item) => item.title !== 'Security')
        : baseSidebarNavItems;
    const sidebarNavItems = property
        ? [
              ...accountNavItems,
              {
                  title: 'Notifications',
                  href: editNotifications(),
                  icon: null,
              },
          ]
        : accountNavItems;

    return (
        <div className="px-4 py-6">
            <div className="flex flex-col gap-6 lg:flex-row lg:gap-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col gap-1" aria-label="Account">
                        {sidebarNavItems.map((item, index) => (
                            <Link
                                key={`${toUrl(item.href)}-${index}`}
                                href={item.href}
                                className={cn(
                                    buttonVariants({
                                        size: 'sm',
                                        variant: 'ghost',
                                    }),
                                    'w-full justify-start',
                                    {
                                        'bg-muted': isCurrentOrParentUrl(
                                            item.href,
                                        ),
                                    },
                                )}
                            >
                                {item.icon && <item.icon className="h-4 w-4" />}
                                {item.title}
                            </Link>
                        ))}
                    </nav>
                </aside>

                <Separator className="lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="flex max-w-xl flex-col gap-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
