import { createInertiaApp } from '@inertiajs/react';
import { FlashToaster } from '@/components/flash-toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AdminLayout from '@/layouts/admin-layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import GuestLayout from '@/layouts/guest-layout';
import OnboardingLayout from '@/layouts/onboarding-layout';
import PartnerLayout from '@/layouts/partner-layout';
import PortalLayout from '@/layouts/portal-layout';
import AccountLayout from '@/layouts/account/layout';
import { setUrlDefaults } from '@/wayfinder';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name, page) => {
        switch (true) {
            case name === 'errors':
                return null;
            case name.startsWith('auth/'):
            case name.startsWith('invitations/'):
            case name === 'property/suspended':
            case name === 'onboarding/waiting':
                return AuthLayout;
            case name.startsWith('onboarding/'):
                return OnboardingLayout;
            case name === 'registration/guest':
            case name === 'registration/done':
                return GuestLayout;
            case name.startsWith('admin/'):
                return AdminLayout;
            case name.startsWith('partner/'):
                return PartnerLayout;
            case name.startsWith('portal/'):
                return PortalLayout;
            case name === 'view-as/show':
                // A support user sees the page in the layout they came from.
                return page.props.adminNavigation ? AdminLayout : PartnerLayout;
            case name.startsWith('account/'):
                return AccountLayout;
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app, { page }) {
        // A page's breadcrumb links are built before AppLayout renders, so the property default is set here as well. Without
        // it the server render writes `/p/$property/...` and the browser writes the real code, which breaks hydration.
        setUrlDefaults({
            property: (page.props.property as { code?: string } | null)?.code,
        });

        return (
            <TooltipProvider delay={0}>
                <FlashToaster>{app}</FlashToaster>
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
