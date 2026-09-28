import { Activity, Building2, LayoutGrid, Settings } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { NavItem } from '@/types';

/** Icon names sent by `App\Data\Navigation\NavItemData`. */
const icons: Record<string, LucideIcon> = {
    'layout-grid': LayoutGrid,
    building: Building2,
    settings: Settings,
    activity: Activity,
};

export function toNavItem(item: App.Data.Navigation.NavItemData): NavItem {
    return { title: item.title, href: item.href, icon: icons[item.icon] };
}
