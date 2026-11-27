import { Rectangle } from 'recharts';
import type { BarShapeProps } from 'recharts';

/**
 * A bar standing on the zero line, rounded at its free end: the series color above zero and `warning` below it (a
 * correction that reversed earlier revenue). Recharts gives a bar below zero a negative size, so it is turned the right
 * way first. `faded` draws a forecast bar lighter. Works for upright bars and, with `horizontal`, for bars laid on
 * their side.
 */
export function ChartBar(
    props: BarShapeProps & { faded?: boolean; horizontal?: boolean },
) {
    const { horizontal = false, faded = false } = props;
    const negative = horizontal ? props.width < 0 : props.height < 0;
    const fill = negative ? 'var(--warning)' : 'var(--color-value)';
    const opacity = (faded ? 0.45 : 1) * (props.isActive ? 0.8 : 1);

    if (horizontal) {
        return (
            <Rectangle
                x={negative ? props.x + props.width : props.x}
                y={props.y}
                width={Math.abs(props.width)}
                height={props.height}
                radius={negative ? [4, 0, 0, 4] : [0, 4, 4, 0]}
                fill={fill}
                fillOpacity={opacity}
            />
        );
    }

    return (
        <Rectangle
            x={props.x}
            y={negative ? props.y + props.height : props.y}
            width={props.width}
            height={Math.abs(props.height)}
            radius={negative ? [0, 0, 4, 4] : [4, 4, 0, 0]}
            fill={fill}
            fillOpacity={opacity}
        />
    );
}
