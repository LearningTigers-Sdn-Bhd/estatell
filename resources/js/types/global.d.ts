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
            isDemo: boolean;
            docsUrl: string;
            property: App.Data.Property.PropertySummaryData | null;
            businessDate: App.Data.NightAudit.BusinessDateData | null;
            adminNavigation: App.Data.Navigation.NavItemData[] | null;
            partnerNavigation: App.Data.Navigation.NavItemData[] | null;
            portalNavigation: App.Data.Navigation.NavItemData[] | null;
            portalCompany: {
                companyName: string;
                propertyName: string;
                propertyCode: string;
                logoUrl: string | null;
            } | null;
            portalSwitcher: App.Data.Portal.PortalSwitcherData[] | null;
            accountNavigation: App.Data.Navigation.NavItemData[] | null;
            navigation: App.Data.Navigation.StaffNavigationData | null;
            channelRemovals: App.Data.Channels.ChannelRemovalData[];
            commandShortcuts: App.Data.Navigation.NavItemData[];
            sidebarOpen: boolean;
            onboarding?: App.Data.Onboarding.OnboardingProgressData | null;
            csrfToken: string;
            [key: string]: unknown;
        };
    }
}
