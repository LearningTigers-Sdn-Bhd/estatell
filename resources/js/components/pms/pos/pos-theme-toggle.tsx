import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAppearance } from '@/hooks/use-appearance';

/**
 * Touch-sized light/dark toggle for full-screen POS views, matching AppNavbar's AppearanceToggle.
 */
export function PosThemeToggle() {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isDark = resolvedAppearance === 'dark';
    const label = isDark ? 'Switch to light mode' : 'Switch to dark mode';

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-11"
                        aria-label={label}
                        aria-pressed={isDark}
                        onClick={() =>
                            updateAppearance(isDark ? 'light' : 'dark')
                        }
                    />
                }
            >
                {isDark ? (
                    <Sun aria-hidden="true" />
                ) : (
                    <Moon aria-hidden="true" />
                )}
            </TooltipTrigger>
            <TooltipContent side="bottom">{label}</TooltipContent>
        </Tooltip>
    );
}
