import { useEffect, useId, useRef, useState } from 'react';

const digits = ['4', '8', '2', '9'];
const keypad = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'];

export function PinArt({ className = '' }: { className?: string }) {
    const id = useId();
    const containerRef = useRef<HTMLDivElement>(null);
    const [started, setStarted] = useState(false);
    useEffect(() => {
        let inView = false;
        const updateVisibility = () => setStarted(inView && !document.hidden);
        const observer = new IntersectionObserver(
            ([entry]) => {
                inView = entry.isIntersecting;
                updateVisibility();
            },
            { threshold: 0 },
        );
        document.addEventListener('visibilitychange', updateVisibility);
        if (containerRef.current) observer.observe(containerRef.current);
        return () => {
            observer.disconnect();
            document.removeEventListener('visibilitychange', updateVisibility);
        };
    }, []);
    const paint = (name: string) => `url(#${id}-${name})`;

    return (
        <div ref={containerRef} className={className} data-started={started}>
            <style>{`
[data-started="false"] .pin-art-art * { animation-play-state: paused; }
.pin-art-art { width: 100%; overflow: visible; }
/* One shared eight-second cycle keeps entry, unlock and reset in sync. */
.pin-art-entered { opacity: 0; animation: 8s ease-out infinite; }
.pin-art-keypress { opacity: 0; animation: 8s ease-out infinite; }
.pin-art-handle { transform-origin: 171px 276px; animation: pin-unlock 8s ease-in-out infinite; }
.pin-art-connection { animation: pin-send 8s linear infinite; }
.pin-art-arrow { animation: pin-arrow 8s linear infinite; }
.pin-art-opened { opacity: 0; animation: pin-open 8s ease-out infinite; }

${digits
    .map(
        (_, i) => `
@keyframes pin-digit-${i} { 0%, ${18 + i * 6}% { opacity: 0; } ${20 + i * 6}%, 82% { opacity: 1; } 88%, 100% { opacity: 0; } }
@keyframes pin-key-${i} { 0%, ${18 + i * 6}% { opacity: 0; } ${20 + i * 6}% { opacity: .5; } ${24 + i * 6}%, 100% { opacity: 0; } }
`,
    )
    .join('')}
@keyframes pin-unlock { 0%, 45%, 100% { transform: rotate(0); } 57%, 82% { transform: rotate(-24deg); } }
@keyframes pin-open { 0%, 50%, 90%, 100% { opacity: 0; } 55%, 82% { opacity: 1; } }
@keyframes pin-send { 0% { stroke-dashoffset: 40; opacity: 0; } 3%, 15% { opacity: 1; } 20%, 90% { stroke-dashoffset: 0; opacity: .25; } 100% { opacity: 0; } }
@keyframes pin-arrow { 0%, 100% { opacity: 0; } 15% { opacity: 1; } 20%, 90% { opacity: .25; } }

@media (prefers-reduced-motion: reduce) {
  .pin-art-art * { animation: none; }
  .pin-art-entered, .pin-art-opened { opacity: 1; }
  .pin-art-connection, .pin-art-arrow { opacity: .25; }
  .pin-art-handle { transform: rotate(-24deg); }
}
`}</style>
            <svg
                viewBox="0 0 530 390"
                role="img"
                aria-label="Smart lock with an illuminated keypad and metal lever on a walnut door, beside a phone displaying a sample PIN for room 204"
                className="pin-art-art"
            >
                <defs>
                    <linearGradient id={`${id}-wood`} x2="1" y2="0">
                        <stop stopColor="#35271e" />
                        <stop offset=".45" stopColor="#73533b" />
                        <stop offset=".88" stopColor="#493425" />
                        <stop offset="1" stopColor="#281e18" />
                    </linearGradient>
                    <linearGradient id={`${id}-metal`} x2="1" y2=".25">
                        <stop stopColor="#747a77" />
                        <stop offset=".12" stopColor="#303735" />
                        <stop offset=".5" stopColor="#151b19" />
                        <stop offset=".88" stopColor="#3d4541" />
                        <stop offset="1" stopColor="#929891" />
                    </linearGradient>
                    <linearGradient id={`${id}-glass`} x2="1" y2="1">
                        <stop stopColor="#35413c" />
                        <stop offset=".48" stopColor="#101815" />
                        <stop offset="1" stopColor="#080d0b" />
                    </linearGradient>
                    <linearGradient id={`${id}-lever`} x2="0" y2="1">
                        <stop stopColor="#a0a6a1" />
                        <stop offset=".2" stopColor="#59625c" />
                        <stop offset=".55" stopColor="#2c3430" />
                        <stop offset="1" stopColor="#101713" />
                    </linearGradient>
                    <filter
                        id={`${id}-shadow`}
                        x="-40%"
                        y="-30%"
                        width="180%"
                        height="180%"
                    >
                        <feDropShadow
                            dx="0"
                            dy="12"
                            stdDeviation="12"
                            floodColor="#000"
                            floodOpacity=".4"
                        />
                    </filter>
                </defs>

                {/* A cropped door surface keeps the hardware large enough to read. */}
                <g filter={paint('shadow')}>
                    <rect
                        x="38"
                        y="16"
                        width="230"
                        height="352"
                        rx="12"
                        fill={paint('wood')}
                    />
                    <path
                        d="M54 20v344M79 18v348M112 18v348M119 18v348M156 18v348M205 18v348M233 18v348M249 18v348"
                        stroke="#d8ad7c"
                        strokeOpacity=".1"
                    />
                    <path
                        d="M65 18c-8 90 12 180 0 348M95 18c15 110-8 230 4 348M185 18c-12 125 15 242 0 348M220 18c12 100-9 250 0 348"
                        fill="none"
                        stroke="#160f0a"
                        strokeOpacity=".18"
                    />
                    <path
                        d="M263 28v328"
                        stroke="#c5a17a"
                        strokeOpacity=".35"
                    />
                    <text
                        x="78"
                        y="66"
                        fill="#e8d8bf"
                        fontSize="22"
                        fontWeight="500"
                        letterSpacing="4"
                    >
                        204
                    </text>
                    <rect
                        x="136"
                        y="91"
                        width="80"
                        height="237"
                        rx="16"
                        fill="#000"
                        opacity=".25"
                    />
                    <rect
                        x="131"
                        y="86"
                        width="80"
                        height="237"
                        rx="16"
                        fill={paint('metal')}
                        stroke="#9ba59b"
                        strokeOpacity=".45"
                    />
                    <rect
                        x="137"
                        y="92"
                        width="68"
                        height="164"
                        rx="12"
                        fill={paint('glass')}
                        stroke="#64756b"
                        strokeOpacity=".3"
                    />
                    <path
                        d="M144 103h51"
                        stroke="#fff"
                        strokeOpacity=".12"
                        strokeLinecap="round"
                    />
                    <rect
                        x="160"
                        y="107"
                        width="22"
                        height="3"
                        rx="1.5"
                        fill="#6ce7b9"
                    />
                    <rect
                        x="144"
                        y="115"
                        width="54"
                        height="17"
                        rx="4"
                        fill="#08110d"
                    />
                    {digits.map((digit, i) => (
                        <text
                            key={i}
                            x={151 + i * 13}
                            y="127"
                            textAnchor="middle"
                            fill="#6ee7b7"
                            fontSize="11"
                            className="pin-art-entered"
                            style={{ animationName: `pin-digit-${i}` }}
                        >
                            {digit}
                        </text>
                    ))}
                    <g
                        transform="translate(0 10)"
                        fill="#e3f5eb"
                        fontSize="12"
                        fontWeight="500"
                        textAnchor="middle"
                    >
                        {keypad.map((key, i) => (
                            <g key={key}>
                                {digits.includes(key) && (
                                    <circle
                                        cx={151 + (i % 3) * 20}
                                        cy={136 + Math.floor(i / 3) * 25}
                                        r="9"
                                        fill="#6ee7b7"
                                        className="pin-art-keypress"
                                        style={{
                                            animationName: `pin-key-${digits.indexOf(key)}`,
                                        }}
                                    />
                                )}
                                <text
                                    x={151 + (i % 3) * 20}
                                    y={140 + Math.floor(i / 3) * 25}
                                >
                                    {key}
                                </text>
                            </g>
                        ))}
                    </g>
                    <path
                        d="M164 239q7-10 14 0m-11-4q4-5 8 0"
                        fill="none"
                        stroke="#6ce7b9"
                        strokeWidth="1.5"
                        strokeLinecap="round"
                    />
                    <circle
                        cx="171"
                        cy="276"
                        r="19"
                        fill={paint('metal')}
                        stroke="#8b948c"
                        strokeOpacity=".5"
                    />
                    <g className="pin-art-handle">
                        <rect
                            x="76"
                            y="273"
                            width="106"
                            height="18"
                            rx="8"
                            fill="#000"
                            opacity=".3"
                        />
                        <rect
                            x="73"
                            y="265"
                            width="108"
                            height="18"
                            rx="8"
                            fill={paint('lever')}
                            stroke="#a2aaa4"
                            strokeOpacity=".4"
                        />
                        <path
                            d="M81 268h86"
                            stroke="#d3d9d3"
                            strokeOpacity=".35"
                            strokeLinecap="round"
                        />
                    </g>
                    <circle
                        cx="171"
                        cy="307"
                        r="4"
                        fill="#0a110e"
                        stroke="#58645b"
                    />
                    <path d="M171 305v4" stroke="#8c978e" />
                </g>

                <g
                    fill="none"
                    stroke="#6ee7b7"
                    strokeWidth="2"
                    strokeLinecap="round"
                >
                    <path
                        d="M312 210C290 210 294 160 222 160"
                        strokeDasharray="3 7"
                        className="pin-art-connection"
                    />
                    <path d="m230 154-8 6 8 6" className="pin-art-arrow" />
                </g>
                <g className="pin-art-opened">
                    <rect
                        x="98"
                        y="337"
                        width="116"
                        height="28"
                        rx="14"
                        fill="#153d2b"
                        stroke="#6ee7b7"
                        strokeOpacity=".5"
                    />
                    <path
                        d="m111 351 4 4 7-8"
                        fill="none"
                        stroke="#6ee7b7"
                        strokeWidth="2"
                        strokeLinecap="round"
                    />
                    <text
                        x="164"
                        y="355"
                        textAnchor="middle"
                        fill="#9af5cc"
                        fontSize="12"
                        fontWeight="600"
                    >
                        Opened
                    </text>
                </g>

                {/* Example PIN delivery, not a live unlock control. */}
                <g
                    transform="translate(316 30) scale(1.12)"
                    filter={paint('shadow')}
                >
                    <rect
                        x="-3"
                        y="67"
                        width="4"
                        height="24"
                        rx="2"
                        fill="#768079"
                    />
                    <rect
                        x="159"
                        y="85"
                        width="4"
                        height="36"
                        rx="2"
                        fill="#768079"
                    />
                    <rect
                        width="160"
                        height="299"
                        rx="28"
                        fill="#151b18"
                        stroke="#8a958e"
                        strokeWidth="2"
                    />
                    <rect
                        x="6"
                        y="6"
                        width="148"
                        height="287"
                        rx="23"
                        fill="#f5f7f2"
                    />
                    <rect
                        x="53"
                        y="12"
                        width="54"
                        height="15"
                        rx="7.5"
                        fill="#101713"
                    />
                    <circle cx="98" cy="19" r="2.5" fill="#243b34" />
                    <text
                        x="80"
                        y="54"
                        textAnchor="middle"
                        fill="#527062"
                        fontSize="10"
                        fontWeight="600"
                        letterSpacing="1.5"
                    >
                        WELCOME IN
                    </text>
                    <text
                        x="80"
                        y="80"
                        textAnchor="middle"
                        fill="#152e23"
                        fontSize="23"
                        fontWeight="600"
                    >
                        Room 204
                    </text>
                    <text
                        x="80"
                        y="100"
                        textAnchor="middle"
                        fill="#66766b"
                        fontSize="10"
                    >
                        Make yourself at home.
                    </text>
                    <rect
                        x="16"
                        y="117"
                        width="128"
                        height="86"
                        rx="12"
                        fill="#e6ede4"
                        stroke="#d9e2d7"
                    />
                    <text
                        x="80"
                        y="137"
                        textAnchor="middle"
                        fill="#506757"
                        fontSize="11"
                    >
                        Your door PIN
                    </text>
                    {digits.map((digit, i) => (
                        <g key={i}>
                            <rect
                                x={23 + i * 29}
                                y="148"
                                width="27"
                                height="36"
                                rx="6"
                                fill="#fff"
                            />
                            <text
                                x={36.5 + i * 29}
                                y="174"
                                textAnchor="middle"
                                fill="#183d2b"
                                fontSize="24"
                                fontWeight="600"
                            >
                                {digit}
                            </text>
                        </g>
                    ))}
                    <circle cx="37" cy="225" r="9" fill="#cef3df" />
                    <path
                        d="m33 225 3 3 5-6"
                        fill="none"
                        stroke="#18845d"
                        strokeWidth="1.7"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                    <text
                        x="52"
                        y="229"
                        fill="#245b40"
                        fontSize="10"
                        fontWeight="600"
                    >
                        Ready to enter
                    </text>
                    <text
                        x="80"
                        y="251"
                        textAnchor="middle"
                        fill="#506757"
                        fontSize="10"
                    >
                        Enter PIN on the keypad.
                    </text>
                    <text
                        x="80"
                        y="269"
                        textAnchor="middle"
                        fill="#66766b"
                        fontSize="9"
                    >
                        Illustrative PIN
                    </text>
                    <rect
                        x="57"
                        y="282"
                        width="46"
                        height="3"
                        rx="1.5"
                        fill="#172e22"
                        opacity=".7"
                    />
                </g>
            </svg>
        </div>
    );
}
