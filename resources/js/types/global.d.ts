import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            adminNeedsTwoFactor: boolean;
            property: App.Data.Property.PropertySummaryData | null;
            adminNavigation: App.Data.Navigation.NavItemData[] | null;
            settingsNavigation: App.Data.Navigation.NavItemData[] | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
