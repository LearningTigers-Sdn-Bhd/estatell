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
            siteUrl: string;
            auth: Auth;
            impersonation: App.Data.Platform.ImpersonationData | null;
            supportTwoFactor: { required: boolean } | null;
            isDemo: boolean;
            docsUrl: string;
            property: App.Data.Property.PropertySummaryData | null;
            businessDate: App.Data.NightAudit.BusinessDateData | null;
            adminNavigation: App.Data.Navigation.NavGroupData[] | null;
            partnerNavigation: App.Data.Navigation.NavItemData[] | null;
            portalNavigation: App.Data.Navigation.NavItemData[] | null;
            portalCompany: {
                companyName: string;
                propertyName: string;
                propertyCode: string;
                logoUrl: string | null;
            } | null;
            portalSwitcher: App.Data.Portal.PortalSwitcherData[] | null;
            /** The other properties a staff user may open, for the navbar switcher. Empty with one property. */
            propertySwitcher: App.Data.Property.PropertySummaryData[];
            /** The groups the staff user may open in group mode, for the top of the switcher. */
            groupSwitcher: { id: number; name: string }[];
            /** The group of a group-mode page (`/g/{group}`), null elsewhere. */
            propertyGroup: { id: number; name: string } | null;
            accountNavigation: App.Data.Navigation.NavItemData[] | null;
            navigation: App.Data.Navigation.StaffNavigationData | null;
            channelRemovals: App.Data.Channels.ChannelRemovalData[];
            commandShortcuts: App.Data.Navigation.NavItemData[];
            sidebarOpen: boolean;
            onboarding?: App.Data.Onboarding.OnboardingProgressData | null;
            csrfToken: string;
            bookingMode?: App.Enums.BookingEngineMode;
            bookingWebsiteHeader?: App.Data.BookingEngine.BookingWebsiteHeaderData;
            [key: string]: unknown;
        };
    }
}
