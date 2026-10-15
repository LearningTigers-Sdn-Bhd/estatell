import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo({ withIcon = false }: { withIcon?: boolean }) {
    if (withIcon) {
        return (
            <>
                <img
                    src="/icon.png"
                    alt="Estatell"
                    width={32}
                    height={32}
                    className="size-8 shrink-0 rounded-md"
                />
                <AppLogoIcon
                    aria-hidden
                    className="group-data-[collapsible=icon]:hidden"
                />
            </>
        );
    }

    return (
        <>
            <AppLogoIcon className="group-data-[collapsible=icon]:hidden" />
            <div className="hidden size-8 shrink-0 items-center justify-center group-data-[collapsible=icon]:flex">
                <AppLogoIcon compact />
            </div>
        </>
    );
}
