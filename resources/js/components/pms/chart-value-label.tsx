import type { LabelProps } from 'recharts';

/** More bars than this and an upright chart writes its figures smaller. */
const CROWDED = 16;

/** The rough width of one character of the figures, as a share of the font size. */
const CHARACTER_WIDTH = 0.6;

/** The room a chart leaves above its bars for the figures. */
export const VALUE_LABEL_TOP = 20;

/**
 * The figure of each bar, written level on the chart so nobody has to hover: above an upright bar (below one under
 * zero), or past the end of a bar laid on its side. A crowded upright chart writes them smaller, and leaves out a figure
 * wider than its bar's slot (the tooltip still has it) so neighbours never overlap. Use as the `content` of a recharts
 * `LabelList` inside the `Bar`. Display only: the figures come from the server.
 */
export function valueLabel(
    format: (value: number) => string,
    { bars, horizontal = false }: { bars: number; horizontal?: boolean },
) {
    return function ValueLabel(props: LabelProps) {
        const x = Number(props.x ?? 0);
        const y = Number(props.y ?? 0);
        const width = Number(props.width ?? 0);
        const height = Number(props.height ?? 0);
        const value = Number(props.value);

        if (!Number.isFinite(value) || props.value === null) {
            return null;
        }

        const text = format(value);
        const crowded = !horizontal && bars > CROWDED;
        const fontSize = crowded ? 10 : 11;
        const common = {
            className: 'fill-foreground tabular-nums',
            fontSize,
        };

        if (
            crowded &&
            text.length * fontSize * CHARACTER_WIDTH > Math.abs(width) + 6
        ) {
            return null;
        }

        if (horizontal) {
            const end =
                value < 0 ? Math.min(x, x + width) : Math.max(x, x + width);

            return (
                <text
                    {...common}
                    x={value < 0 ? end - 4 : end + 4}
                    y={y + height / 2}
                    textAnchor={value < 0 ? 'end' : 'start'}
                    dominantBaseline="central"
                >
                    {text}
                </text>
            );
        }

        const centre = x + width / 2;

        if (value < 0) {
            return (
                <text
                    {...common}
                    x={centre}
                    y={Math.max(y, y + height) + 12}
                    textAnchor="middle"
                >
                    {text}
                </text>
            );
        }

        const top = Math.min(y, y + height) - 4;

        return (
            <text {...common} x={centre} y={top} textAnchor="middle">
                {text}
            </text>
        );
    };
}
