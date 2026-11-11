import * as flags from 'country-flag-icons/react/3x2';

type Props = {
    code: string;
    className: string;
};

/**
 * All the SVG flags. They are large, so only `CountryFlag` imports this file, and it loads on its own.
 */
export default function CountryFlagSvg({ code, className }: Props) {
    const flag = flags[code as keyof typeof flags];

    return flag ? (
        flag({ 'aria-hidden': true, className })
    ) : (
        <span aria-hidden className={className} />
    );
}
