import {
    Activity,
    Ban,
    Bed,
    Bell,
    Building2,
    ChartColumn,
    ConciergeBell,
    CreditCard,
    DoorOpen,
    Landmark,
    LayoutGrid,
    Percent,
    Settings,
    Shield,
    Users,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { NavItem } from '@/types';

/** Icon names sent by `App\Data\Navigation\NavItemData` and `App\Enums\NavigationContext`. */
const icons: Record<string, LucideIcon> = {
    'layout-grid': LayoutGrid,
    building: Building2,
    bed: Bed,
    'door-open': DoorOpen,
    settings: Settings,
    activity: Activity,
    bell: Bell,
    'concierge-bell': ConciergeBell,
    wallet: Wallet,
    'credit-card': CreditCard,
    landmark: Landmark,
    percent: Percent,
    ban: Ban,
    'chart-column': ChartColumn,
    users: Users,
    shield: Shield,
};

export function navIcon(name: string): LucideIcon | undefined {
    return icons[name];
}

export function toNavItem(item: App.Data.Navigation.NavItemData): NavItem {
    return {
        title: item.title,
        href: item.href,
        icon: icons[item.icon],
        isActive: item.active,
    };
}
