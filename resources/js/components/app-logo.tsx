import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <AppLogoIcon className="group-data-[collapsible=icon]:hidden" />
            <div className="hidden size-8 shrink-0 items-center justify-center group-data-[collapsible=icon]:flex">
                <AppLogoIcon compact />
            </div>
        </>
    );
}
