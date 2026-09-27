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
            adminNavigation: App.Data.Navigation.NavItemData[] | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
