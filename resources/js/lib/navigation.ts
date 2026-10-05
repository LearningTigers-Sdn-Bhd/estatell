import {
    Activity,
    Baby,
    Ban,
    Bed,
    Bell,
    Building2,
    CalendarCheck,
    CalendarRange,
    ChartColumn,
    ConciergeBell,
    CreditCard,
    DoorOpen,
    IdCard,
    Landmark,
    LayoutGrid,
    Percent,
    Receipt,
    Settings,
    Shield,
    Tag,
    Users,
    Utensils,
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
    'id-card': IdCard,
    settings: Settings,
    activity: Activity,
    bell: Bell,
    'concierge-bell': ConciergeBell,
    wallet: Wallet,
    'credit-card': CreditCard,
    landmark: Landmark,
    percent: Percent,
    receipt: Receipt,
    utensils: Utensils,
    baby: Baby,
    ban: Ban,
    'calendar-check': CalendarCheck,
    'calendar-range': CalendarRange,
    'chart-column': ChartColumn,
    users: Users,
    shield: Shield,
    tag: Tag,
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
