import { Link, usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useInitials } from '@/hooks/use-initials';
import { properties, show } from '@/routes/portal';

function PropertyInitials({ name }: { name: string }) {
    const getInitials = useInitials();

    return (
        <Avatar className="rounded-md after:rounded-md">
            <AvatarFallback className="rounded-md bg-primary text-xs font-semibold text-primary-foreground">
                {getInitials(name)}
            </AvatarFallback>
        </Avatar>
    );
}

/**
 * Property switcher at the top of the portal sidebar inside a property: the current property and company, the user's
 * other Live properties and a link to all of them. The server sends the list as `portalSwitcher`.
 */
export function PortalPropertySwitcher() {
    const { portalCompany, portalSwitcher } = usePage().props;

    if (!portalCompany) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        render={
                            <SidebarMenuButton
                                size="lg"
                                tooltip={portalCompany.propertyName}
                                aria-label={`Switch property, now ${portalCompany.propertyName}`}
                            />
                        }
                    >
                        <PropertyInitials name={portalCompany.propertyName} />
                        <div className="grid min-w-0 flex-1 text-left text-sm leading-tight">
                            <span className="truncate font-semibold">
                                {portalCompany.propertyName}
                            </span>
                            <span className="truncate text-xs text-muted-foreground">
                                {portalCompany.companyName}
                            </span>
                        </div>
                        <ChevronsUpDown className="ml-auto group-data-[collapsible=icon]:hidden" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="min-w-56 rounded-lg"
                        align="start"
                        side="bottom"
                    >
                        {(portalSwitcher ?? []).map((item) => (
                            <DropdownMenuItem
                                key={item.propertyCode}
                                render={
                                    <Link
                                        href={show({
                                            property: item.propertyCode,
                                        })}
                                    />
                                }
                            >
                                <PropertyInitials name={item.propertyName} />
                                <div className="grid min-w-0 text-left leading-tight">
                                    <span className="truncate font-medium">
                                        {item.propertyName}
                                    </span>
                                    <span className="truncate text-xs text-muted-foreground">
                                        {item.companyName}
                                    </span>
                                </div>
                            </DropdownMenuItem>
                        ))}
                        {(portalSwitcher ?? []).length > 0 && (
                            <DropdownMenuSeparator />
                        )}
                        <DropdownMenuItem render={<Link href={properties()} />}>
                            All properties
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
