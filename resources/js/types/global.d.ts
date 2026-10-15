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
            impersonation: App.Data.Platform.ImpersonationData | null;
            supportTwoFactor: { required: boolean } | null;
            property: App.Data.Property.PropertySummaryData | null;
            businessDate: App.Data.NightAudit.BusinessDateData | null;
            adminNavigation: App.Data.Navigation.NavItemData[] | null;
            partnerNavigation: App.Data.Navigation.NavItemData[] | null;
            accountNavigation: App.Data.Navigation.NavItemData[] | null;
            navigation: App.Data.Navigation.StaffNavigationData | null;
            sidebarOpen: boolean;
            onboarding?: App.Data.Onboarding.OnboardingProgressData | null;
            csrfToken: string;
            [key: string]: unknown;
        };
    }
}
