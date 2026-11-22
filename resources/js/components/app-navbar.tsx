import { Link, usePage } from '@inertiajs/react';
import { ChevronsUpDown, CircleHelp, Network } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { AppearanceToggle } from '@/components/appearance-toggle';
import { NavUser } from '@/components/nav-user';
import { CommandPalette } from '@/components/pms/command-palette';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { buttonVariants } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { dashboard as groupDashboard } from '@/routes/group';
import { redirect as homeRedirect } from '@/routes/home';

/** The property logo (or its initials), name and code. */
function PropertyIdentity({
    property,
}: {
    property: App.Data.Property.PropertySummaryData;
}) {
    const getInitials = useInitials();

    return (
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
                <span className="truncate font-semibold">{property.name}</span>
                <span className="truncate text-xs text-muted-foreground">
                    ID {property.code}
                </span>
            </div>
        </>
    );
}

/** A group of properties: its name, and that it covers all of them. */
function GroupIdentity({ name }: { name: string }) {
    return (
        <>
            <Avatar className="overflow-hidden rounded-md after:rounded-md">
                <AvatarFallback className="rounded-md bg-primary text-primary-foreground">
                    <Network className="size-4" />
                </AvatarFallback>
            </Avatar>
            <div className="grid min-w-0 text-left text-sm leading-tight">
                <span className="truncate font-semibold">{name}</span>
                <span className="truncate text-xs text-muted-foreground">
                    All properties
                </span>
            </div>
        </>
    );
}

/**
 * Navbar: sidebar toggle, property identity (or the app logo) and the user menu.
 * Staff with access to more than one property switch between them from the identity (docs/03-staff/04-property-groups.md).
 * See docs/design-system/09-app-shell.md.
 */
export function AppNavbar({
    identity = 'property',
    surface = 'default',
    palette = true,
    logoHref,
}: {
    identity?: 'property' | 'app';
    surface?: 'default' | 'sidebar';
    /** Pages whose users cannot reach staff pages (the company portal) hide the ⌘K palette. */
    palette?: boolean;
    /** Where the app logo links. Defaults to the home redirect. */
    logoHref?: string;
}) {
    const {
        property: currentProperty,
        propertySwitcher,
        groupSwitcher,
        propertyGroup,
        docsUrl,
    } = usePage().props;
    const property = identity === 'property' ? currentProperty : null;
    const group = identity === 'property' ? propertyGroup : null;

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
            {(property || group) &&
            (propertySwitcher.length > 0 || groupSwitcher.length > 0) ? (
                <DropdownMenu>
                    <DropdownMenuTrigger
                        className="flex min-w-0 shrink-0 items-center gap-2 rounded-md p-1 outline-hidden hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label={`Switch property, now ${group?.name ?? property?.name}`}
                    >
                        {group ? (
                            <GroupIdentity name={group.name} />
                        ) : (
                            property && <PropertyIdentity property={property} />
                        )}
                        <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="min-w-64"
                        align="start"
                        side="bottom"
                    >
                        {groupSwitcher.length > 0 && (
                            <>
                                <DropdownMenuGroup>
                                    <DropdownMenuLabel>
                                        All properties
                                    </DropdownMenuLabel>
                                    {groupSwitcher.map((item) => (
                                        <DropdownMenuItem
                                            key={item.id}
                                            render={
                                                <Link
                                                    href={groupDashboard({
                                                        propertyGroup: item.id,
                                                    })}
                                                />
                                            }
                                        >
                                            <GroupIdentity name={item.name} />
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuGroup>
                                {propertySwitcher.length > 0 && (
                                    <DropdownMenuSeparator />
                                )}
                            </>
                        )}
                        {propertySwitcher.length > 0 && (
                            <DropdownMenuGroup>
                                <DropdownMenuLabel>
                                    {group
                                        ? 'Open a property'
                                        : 'Switch property'}
                                </DropdownMenuLabel>
                                {propertySwitcher.map((item) => (
                                    <DropdownMenuItem
                                        key={item.code}
                                        render={
                                            <Link
                                                href={dashboard({
                                                    property: item.code,
                                                })}
                                            />
                                        }
                                    >
                                        <PropertyIdentity property={item} />
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuGroup>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : (
                <Link
                    href={property ? dashboard() : (logoHref ?? homeRedirect())}
                    prefetch
                    className="flex min-w-0 shrink-0 items-center gap-2 rounded-md p-1 outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {property ? (
                        <PropertyIdentity property={property} />
                    ) : (
                        <AppLogo />
                    )}
                </Link>
            )}
            <div className="flex min-w-0 flex-1 justify-center px-2">
                {palette && (
                    <div className="w-auto sm:w-full sm:max-w-md">
                        <CommandPalette />
                    </div>
                )}
            </div>
            <div className="flex shrink-0 items-center gap-1">
                {property && (
                    <Tooltip>
                        <TooltipTrigger
                            render={
                                <a
                                    href={docsUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label="Help & docs"
                                    className={buttonVariants({
                                        variant: 'ghost',
                                        size: 'icon',
                                    })}
                                />
                            }
                        >
                            <CircleHelp />
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                            Help & docs
                        </TooltipContent>
                    </Tooltip>
                )}
                <AppearanceToggle />
                <NavUser />
            </div>
        </header>
    );
}
