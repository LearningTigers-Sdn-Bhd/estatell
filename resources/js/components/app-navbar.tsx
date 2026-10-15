import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { AppearanceToggle } from '@/components/appearance-toggle';
import { NavUser } from '@/components/nav-user';
import { CommandPalette } from '@/components/pms/command-palette';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { redirect as homeRedirect } from '@/routes/home';

/**
 * Staff navbar: sidebar toggle, property identity and the user menu.
 * See docs/design-system/09-app-shell.md.
 */
export function AppNavbar({
    identity = 'property',
    surface = 'default',
}: {
    identity?: 'property' | 'app';
    surface?: 'default' | 'sidebar';
}) {
    const { property: currentProperty } = usePage().props;
    const property = identity === 'property' ? currentProperty : null;
    const getInitials = useInitials();

    return (
        <header
            className={cn(
                'z-30 flex h-14 shrink-0 items-center gap-2 border-b px-4',
                surface === 'sidebar'
                    ? 'border-sidebar-border bg-sidebar text-sidebar-foreground'
                    : 'bg-background',
            )}
        >
            <SidebarTrigger className="-ml-1" />
            <Link
                href={property ? dashboard() : homeRedirect()}
                prefetch
                className="flex min-w-0 shrink-0 items-center gap-2 rounded-md p-1 outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
            >
                {property ? (
                    <>
                        <Avatar
                            key={property.logoUrl}
                            className="overflow-hidden rounded-md after:rounded-md"
                        >
                            <AvatarImage
                                src={property.logoUrl ?? undefined}
                                alt=""
                                className="rounded-md object-contain"
                            />
                            <AvatarFallback className="rounded-md bg-primary text-xs font-semibold text-primary-foreground">
                                {getInitials(property.name)}
                            </AvatarFallback>
                        </Avatar>
                        <div className="grid min-w-0 text-left text-sm leading-tight">
                            <span className="truncate font-semibold">
                                {property.name}
                            </span>
                            <span className="truncate text-xs text-muted-foreground">
                                ID {property.code}
                            </span>
                        </div>
                    </>
                ) : (
                    <AppLogo />
                )}
            </Link>
            <div className="flex min-w-0 flex-1 justify-center px-2">
                <div className="w-auto sm:w-full sm:max-w-md">
                    <CommandPalette />
                </div>
            </div>
            <div className="flex shrink-0 items-center gap-1">
                <AppearanceToggle />
                <NavUser />
            </div>
        </header>
    );
}
