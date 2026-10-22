import { useId } from 'react';

export function LivingHotel() {
    const id = useId();

    return (
        <figure className="living-hotel relative mx-auto w-full max-w-[600px]">
            <div className="[container-type:inline-size] relative">
                <svg
                    viewBox="0 0 640 660"
                    fill="none"
                    fontFamily="var(--font-sans), sans-serif"
                    role="img"
                    aria-labelledby={`${id}-title ${id}-description`}
                    className="h-auto w-full overflow-visible"
                >
                    <title id={`${id}-title`}>
                        One hotel, connected by Estatell
                    </title>
                    <desc id={`${id}-description`}>
                        An architectural miniature with a guest suite, dining
                        terrace, and reception. Emerald connections link a
                        direct booking to a ready room and a warm welcome.
                        Illustrative, not live activity.
                    </desc>
                    <defs>
                        <linearGradient
                            id={`${id}-stone`}
                            x1="120"
                            y1="0"
                            x2="490"
                            y2="230"
                            gradientUnits="userSpaceOnUse"
                        >
                            <stop stopColor="#f1eadb" />
                            <stop offset="1" stopColor="#b8ac95" />
                        </linearGradient>
                        <linearGradient
                            id={`${id}-wall`}
                            x1="150"
                            y1="50"
                            x2="400"
                            y2="180"
                            gradientUnits="userSpaceOnUse"
                        >
                            <stop stopColor="#d8ccb7" />
                            <stop offset="1" stopColor="#8e8069" />
                        </linearGradient>
                        <linearGradient
                            id={`${id}-glass`}
                            x1="0"
                            y1="0"
                            x2="1"
                            y2="1"
                        >
                            <stop stopColor="#254d3b" />
                            <stop offset="1" stopColor="#10241c" />
                        </linearGradient>
                        <radialGradient id={`${id}-halo`}>
                            <stop stopColor="#6ee7b7" stopOpacity="0.13" />
                            <stop
                                offset="1"
                                stopColor="#6ee7b7"
                                stopOpacity="0"
                            />
                        </radialGradient>
                        <g id={`${id}-plant`}>
                            <path
                                d="M-10 0 Q-9 21 0 23 Q9 21 10 0Z"
                                fill="#b5a38a"
                            />
                            <ellipse rx="10" ry="4" fill="#d8c9b1" />
                            <path
                                d="M0 2V-35"
                                stroke="#638d65"
                                strokeWidth="3"
                            />
                            <path
                                d="M0-12C-28-12-24-36-5-26L0-12ZM0-20C24-23 24-46 5-35L0-20ZM0-30C-16-39-12-56 0-48C12-40 9-34 0-30Z"
                                fill="#437454"
                            />
                            <path d="M0-9C21-7 26-27 8-23Z" fill="#6c976d" />
                        </g>
                    </defs>

                    <g className="hotel-foundation">
                        <ellipse
                            cx="325"
                            cy="330"
                            rx="315"
                            ry="310"
                            fill={`url(#${id}-halo)`}
                        />
                        <ellipse
                            cx="315"
                            cy="581"
                            rx="220"
                            ry="44"
                            fill="#000"
                            opacity="0.22"
                        />
                        <path
                            d="M75 492 303 382 559 506 330 623Z"
                            stroke="#6ee7b7"
                            strokeOpacity="0.2"
                        />
                        <path
                            d="M91 486 303 384 543 501 330 608Z"
                            fill="#193b2a"
                        />
                        <path d="M91 486V500L330 622V608Z" fill="#10271c" />
                        <path
                            d="M330 608 543 501V515L330 622Z"
                            fill="#214532"
                        />
                    </g>

                    {/* Reception, dining, and guest suite: back-to-front architectural layers. */}
                    {[
                        { y: 354, kind: 'reception' },
                        { y: 204, kind: 'dining' },
                        { y: 54, kind: 'suite' },
                    ].map(({ y, kind }, index) => (
                        <g
                            key={kind}
                            className={`hotel-floor hotel-floor-${index}`}
                        >
                            <g transform={`translate(0 ${y})`}>
                                <path
                                    d="M130 128 302 44 500 141 328 228Z"
                                    fill={`url(#${id}-stone)`}
                                />
                                <path
                                    d="M130 128V140L328 240V228Z"
                                    fill="#968770"
                                />
                                <path
                                    d="M328 228 500 141V153L328 240Z"
                                    fill="#c6b89e"
                                />
                                <path
                                    d="M130 128V59L302-25V44Z"
                                    fill={`url(#${id}-wall)`}
                                />
                                <path
                                    d="M302-25 500 72V141L302 44Z"
                                    fill="#c1b49c"
                                />
                                <path
                                    d="M130 59 302-25 500 72"
                                    stroke="#f2eadb"
                                    strokeWidth="5"
                                    strokeLinejoin="round"
                                />
                                <path
                                    d="M145 118V66L285-3V49Z"
                                    fill={`url(#${id}-glass)`}
                                />
                                <path
                                    d="M190 95V44M237 72V21"
                                    stroke="#a9987a"
                                    strokeWidth="4"
                                />
                                <path
                                    d="M152 78 279 16"
                                    stroke="#c9e5d4"
                                    strokeOpacity="0.22"
                                    strokeWidth="2"
                                />
                                <path
                                    d="M320 1 481 80V127L320 48Z"
                                    fill="#86735a"
                                />
                                {[340, 367, 394, 421, 448, 475].map((x) => (
                                    <path
                                        key={x}
                                        d={`M${x} ${(x - 320) / 2 + 3}v44`}
                                        stroke="#ad9472"
                                        strokeWidth="5"
                                    />
                                ))}
                                <path
                                    d="M135 129 328 225 495 142"
                                    stroke="#f6efdd"
                                    strokeOpacity="0.65"
                                    strokeWidth="2"
                                />

                                {kind === 'suite' && (
                                    <>
                                        <path
                                            d="M216 119 299 78 390 123 307 166Z"
                                            fill="#b7a98d"
                                            opacity="0.65"
                                        />
                                        <path
                                            d="M254 91V65L338 107V134Z"
                                            fill="#715e47"
                                        />
                                        <path
                                            d="M215 121 254 101 338 142 299 162Z"
                                            fill="#ede8db"
                                        />
                                        <path
                                            d="M215 121V140L299 181V162Z"
                                            fill="#cac0ae"
                                        />
                                        <path
                                            d="M299 162 338 142V161L299 181Z"
                                            fill="#f4eee2"
                                        />
                                        <path
                                            d="M217 119 253 101 337 141 300 160Z"
                                            fill="#f7f3e8"
                                        />
                                        <path
                                            d="M234 112 245 106 275 121 264 128ZM269 129 280 123 310 138 299 145Z"
                                            fill="#ddd4c2"
                                        />
                                        <path
                                            d="M217 128 299 168 318 159 236 118Z"
                                            fill="#365e48"
                                        />
                                        <path
                                            d="M299 168V180L318 171V159Z"
                                            fill="#254532"
                                        />
                                        <path
                                            d="M360 122 388 108 414 121 386 135Z"
                                            fill="#a18b69"
                                        />
                                        <path
                                            d="M386 135V161M364 125V148M409 125V145"
                                            stroke="#756047"
                                            strokeWidth="4"
                                        />
                                        <path
                                            d="M387 111V89"
                                            stroke="#ddc5a1"
                                            strokeWidth="3"
                                        />
                                        <path
                                            className="hotel-lamp"
                                            d="M374 92 380 75 395 82 400 104Z"
                                            fill="#ffe7ac"
                                        />
                                        <use
                                            href={`#${id}-plant`}
                                            transform="translate(450 128)"
                                        />
                                    </>
                                )}
                                {kind === 'dining' && (
                                    <>
                                        <path
                                            d="M220 118V150M263 139V173M306 117V148"
                                            stroke="#735e43"
                                            strokeWidth="5"
                                        />
                                        <path
                                            d="M209 111 254 89 320 121 275 145Z"
                                            fill="#d6bc8f"
                                        />
                                        <path
                                            d="M209 111V119L275 152V145ZM275 145 320 121V129L275 152Z"
                                            fill="#ab8b62"
                                        />
                                        <ellipse
                                            cx="251"
                                            cy="112"
                                            rx="13"
                                            ry="6"
                                            fill="#f4ecda"
                                        />
                                        <ellipse
                                            cx="283"
                                            cy="128"
                                            rx="13"
                                            ry="6"
                                            fill="#f4ecda"
                                        />
                                        <path
                                            d="M262 115V99"
                                            stroke="#315b3f"
                                            strokeWidth="4"
                                        />
                                        <ellipse
                                            cx="262"
                                            cy="99"
                                            rx="9"
                                            ry="6"
                                            fill="#5f8f63"
                                        />
                                        <path
                                            d="M203 133V151L232 166V148ZM292 162V180L320 166V148Z"
                                            fill="#355b43"
                                        />
                                        <path
                                            d="M203 151V169M231 165V182M292 180V192M320 166V180"
                                            stroke="#b09873"
                                            strokeWidth="3"
                                        />
                                        <path
                                            d="M365 111 403 92 442 112 404 131Z"
                                            fill="#d6bc8f"
                                        />
                                        <path
                                            d="M404 131V161"
                                            stroke="#a3865e"
                                            strokeWidth="6"
                                        />
                                        <ellipse
                                            cx="402"
                                            cy="110"
                                            rx="11"
                                            ry="5"
                                            fill="#f4ecda"
                                        />
                                        <use
                                            href={`#${id}-plant`}
                                            transform="translate(165 122)"
                                        />
                                        <use
                                            href={`#${id}-plant`}
                                            transform="translate(463 138) scale(.8)"
                                        />
                                    </>
                                )}
                                {kind === 'reception' && (
                                    <>
                                        <path
                                            d="M229 114 286 86 378 132 321 161Z"
                                            fill="#e8dcca"
                                        />
                                        <path
                                            d="M229 114V148L321 195V161Z"
                                            fill="#9b7c56"
                                        />
                                        <path
                                            d="M321 161 378 132V166L321 195Z"
                                            fill="#b69770"
                                        />
                                        {[244, 260, 276, 292, 308].map((x) => (
                                            <path
                                                key={x}
                                                d={`M${x} ${(x - 229) / 2 + 120}v25`}
                                                stroke="#71593e"
                                                strokeWidth="2"
                                            />
                                        ))}
                                        <path
                                            d="M286 115V94L309 105V126Z"
                                            fill="#173d2b"
                                            stroke="#5b816a"
                                            strokeWidth="2"
                                        />
                                        <path
                                            d="M296 121V130L310 137"
                                            stroke="#244934"
                                            strokeWidth="3"
                                        />
                                        <use
                                            href={`#${id}-plant`}
                                            transform="translate(165 119)"
                                        />
                                        <use
                                            href={`#${id}-plant`}
                                            transform="translate(450 145)"
                                        />
                                        <circle
                                            cx="395"
                                            cy="143"
                                            r="8"
                                            fill="#c69f7c"
                                        />
                                        <path
                                            d="M386 157Q395 150 403 160L405 181 386 173Z"
                                            fill="#e9e3d6"
                                        />
                                        <path
                                            d="M390 176V196M401 181V201"
                                            stroke="#293e32"
                                            strokeWidth="5"
                                            strokeLinecap="round"
                                        />
                                        <path
                                            d="M413 180V171L421 175V185"
                                            stroke="#ae9472"
                                            strokeWidth="2"
                                        />
                                        <path
                                            d="M410 180 424 186V203L410 197Z"
                                            fill="#628370"
                                        />
                                    </>
                                )}
                            </g>
                        </g>
                    ))}

                    {/* One visible connection, not a second product UI. */}
                    <path
                        className="hotel-connection"
                        d="M506 190 552 213V363L510 386M552 363V513L500 540"
                        stroke="#6ee7b7"
                        strokeWidth="2"
                        strokeDasharray="5 7"
                    />
                    <g className="hotel-nodes" fill="#6ee7b7">
                        <circle cx="506" cy="190" r="5" />
                        <circle cx="510" cy="386" r="5" />
                        <circle cx="500" cy="540" r="5" />
                    </g>
                </svg>
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 hidden text-[2.2cqw] leading-none font-medium whitespace-nowrap lg:block"
                >
                    <span className="hotel-note hotel-note-0 absolute top-[5.75%] right-[14%] inline-flex items-center gap-[1.4cqw] rounded-sm border border-border bg-foreground px-[2cqw] py-[1.8cqw] text-background after:absolute after:top-full after:left-1/2 after:h-[4cqw] after:w-px after:bg-foreground">
                        <span className="size-[1.2cqw] rounded-full bg-primary" />
                        Booking confirmed
                    </span>
                    <span className="hotel-note hotel-note-1 absolute top-[43%] left-[2%] inline-flex items-center gap-[1.4cqw] rounded-sm border border-border bg-foreground px-[2cqw] py-[1.8cqw] text-background after:absolute after:top-1/2 after:left-full after:h-px after:w-[4cqw] after:bg-foreground">
                        <span className="size-[1.2cqw] rounded-full bg-primary" />
                        Room ready
                    </span>
                    <span className="hotel-note hotel-note-2 absolute top-[80%] right-[1%] inline-flex items-center gap-[1.4cqw] rounded-sm border border-border bg-foreground px-[2cqw] py-[1.8cqw] text-background after:absolute after:top-1/2 after:right-full after:h-px after:w-[4cqw] after:bg-foreground">
                        <span className="size-[1.2cqw] rounded-full bg-primary" />
                        Guest checked in
                    </span>
                </div>
            </div>
        </figure>
    );
}
