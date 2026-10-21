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
    Mail,
    Moon,
    Percent,
    Palette,
    Receipt,
    Settings,
    Ship,
    Shield,
    Tag,
    Ticket,
    Users,
    User,
    Utensils,
    Waypoints,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { NavItem } from '@/types';

/** Icon names sent by `App\Data\Navigation\NavItemData` and `App\Enums\NavigationContext`. */
const icons: Record<string, LucideIcon> = {
    'layout-grid': LayoutGrid,
    building: Building2,
    handshake: Handshake,
    waypoints: Waypoints,
    mail: Mail,
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
    user: User,
    palette: Palette,
    tag: Tag,
    ticket: Ticket,
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
        badge: item.badge,
    };
}
