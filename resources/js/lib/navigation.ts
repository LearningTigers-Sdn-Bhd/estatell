import {
    Printer,
    Activity,
    Baby,
    Ban,
    Bed,
    Bell,
    Building2,
    SprayCan,
    CalendarCheck,
    CalendarRange,
    ChartColumn,
    ConciergeBell,
    CreditCard,
    DoorOpen,
    IdCard,
    Handshake,
    Landmark,
    LayoutGrid,
    Moon,
    Percent,
    Receipt,
    Settings,
    Ship,
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
    handshake: Handshake,
    bed: Bed,
    'door-open': DoorOpen,
    'id-card': IdCard,
    printer: Printer,
    settings: Settings,
    ship: Ship,
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
    moon: Moon,
    shield: Shield,
    tag: Tag,
    'spray-can': SprayCan,
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
        action: item.action,
    };
}
