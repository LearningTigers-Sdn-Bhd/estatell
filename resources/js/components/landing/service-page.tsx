import { ArrowUpRight } from 'lucide-react';
import { Closing } from './closing';
import { Navbar } from './navbar';
import { PageMeta, useSiteUrl } from './seo';
import { ServiceVisual } from './service-visuals';
import { services, type Service } from './services';
import { Container } from './ui';
import { useHeroTimeline, useStaggerReveal } from './use-reveal';

// What a crawler or answer engine needs beyond the Organization: the service,
// where it sits in the site, and the questions the page answers.
function schemaNodes(service: Service, siteUrl: string, url: string) {
    return [
        {
            '@type': 'Service',
            name: `Estatell ${service.name}`,
            description: service.description,
            url,
            serviceType: service.name,
            provider: { '@id': `${siteUrl}/#organization` },
        },
        {
            '@type': 'BreadcrumbList',
            itemListElement: [
                {
                    '@type': 'ListItem',
                    position: 1,
                    name: 'Estatell',
                    item: siteUrl,
                },
                {
                    '@type': 'ListItem',
                    position: 2,
                    name: service.name,
                    item: url,
                },
            ],
        },
        {
            '@type': 'FAQPage',
            mainEntity: service.faqs.map((faq) => ({
                '@type': 'Question',
                name: faq.q,
                acceptedAnswer: { '@type': 'Answer', text: faq.a },
            })),
        },
    ];
}

// The identity motif: outlined numerals, the same treatment as the footer wordmark.
function Numeral({
    value,
    className = '',
}: {
    value: number;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={`font-heading leading-none font-medium text-transparent tabular-nums transition-colors duration-300 [-webkit-text-stroke:1.5px_var(--foreground)] group-hover:text-primary motion-reduce:transition-none ${className}`}
        >
            {String(value).padStart(2, '0')}
        </span>
    );
}

const pad = 'py-12 sm:py-16 lg:py-24';

export function ServicePage({ service }: { service: Service }) {
    const siteUrl = useSiteUrl();
    const path = `/services/${service.slug}`;
    const image =
        service.visual.type === 'photo'
            ? {
                  src: service.visual.src,
                  width: service.visual.width,
                  height: service.visual.height,
              }
            : undefined;
    const others = services.filter((item) => item.slug !== service.slug);
    const heroRef = useHeroTimeline();
    const featuresRef = useStaggerReveal(0.08);
    const flowRef = useStaggerReveal(0.15);
    const rangeRef = useStaggerReveal(0.1);

    return (
        <>
            <PageMeta
                title={service.metaTitle}
                description={service.description}
                path={path}
                image={image}
                nodes={schemaNodes(service, siteUrl, `${siteUrl}${path}`)}
            />
            <Navbar />
            <main id="top" tabIndex={-1}>
                <section
                    aria-labelledby="service-heading"
                    className="dark relative bg-brand-forest pt-28 pb-14 text-foreground sm:pt-36 sm:pb-20"
                >
                    <div
                        ref={heroRef}
                        className="relative mx-auto grid w-full max-w-6xl items-center gap-12 px-5 sm:px-8 lg:grid-cols-[1.05fr_0.95fr] lg:gap-16"
                    >
                        <div className="min-w-0">
                            <nav
                                data-hero-eyebrow
                                aria-label="Breadcrumb"
                                className="text-sm text-foreground/75"
                            >
                                <ol className="flex flex-wrap items-center gap-x-2">
                                    <li>
                                        <a
                                            href="/"
                                            className="inline-flex min-h-11 items-center hover:text-foreground"
                                        >
                                            Estatell
                                        </a>
                                    </li>
                                    <li aria-hidden="true">/</li>
                                    <li
                                        aria-current="page"
                                        className="text-foreground"
                                    >
                                        {service.name}
                                    </li>
                                </ol>
                            </nav>
                            <h1
                                id="service-heading"
                                data-hero-headline
                                className="mt-4 text-[clamp(2.75rem,8vw,5.5rem)] leading-[0.95] font-medium tracking-tighter text-balance"
                            >
                                {service.headline[0]}
                                <br />
                                <span className="text-chart-1">
                                    {service.headline[1]}
                                </span>
                            </h1>
                            <p
                                data-hero-lede
                                className="mt-6 max-w-md text-lg leading-relaxed text-foreground/80"
                            >
                                {service.blurb}
                            </p>
                        </div>
                        <div className="min-w-0">
                            <ServiceVisual visual={service.visual} />
                        </div>
                    </div>
                </section>

                <section aria-labelledby="answer-heading" className={pad}>
                    <Container>
                        <div className="grid gap-6 lg:grid-cols-[0.35fr_1fr] lg:gap-16">
                            <h2
                                id="answer-heading"
                                className="text-sm font-medium text-foreground/70"
                            >
                                The short answer
                            </h2>
                            <p className="max-w-4xl text-2xl leading-snug font-medium tracking-tight text-balance sm:text-3xl lg:text-4xl">
                                {service.answer}
                            </p>
                        </div>
                    </Container>
                </section>

                <section
                    aria-labelledby="features-heading"
                    className={`bg-muted ${pad}`}
                >
                    <Container>
                        <h2
                            id="features-heading"
                            className="max-w-2xl text-4xl leading-none font-medium tracking-tighter text-balance sm:text-6xl"
                        >
                            {service.featuresTitle}
                        </h2>
                        <div ref={featuresRef} className="mt-10 sm:mt-14">
                            <ul className="border-b border-border">
                                {service.features.map((feature, index) => (
                                    <li
                                        key={feature.title}
                                        data-stagger
                                        className="group grid items-baseline gap-x-8 gap-y-3 border-t border-border py-7 transition-colors hover:bg-background/60 sm:grid-cols-[5rem_1fr_1.2fr] lg:grid-cols-[8rem_1fr_1.3fr] lg:py-10"
                                    >
                                        <Numeral
                                            value={index + 1}
                                            className="text-4xl lg:text-6xl"
                                        />
                                        <h3 className="text-2xl font-medium tracking-tight text-balance lg:text-3xl">
                                            {feature.title}
                                        </h3>
                                        <p className="max-w-lg text-base leading-relaxed text-foreground/70">
                                            {feature.body}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </Container>
                </section>

                {service.flow && (
                    <section
                        aria-labelledby="flow-heading"
                        className={`dark bg-brand-forest text-foreground ${pad}`}
                    >
                        <Container>
                            <h2
                                id="flow-heading"
                                className="max-w-2xl text-4xl leading-none font-medium tracking-tighter text-balance sm:text-6xl"
                            >
                                {service.flow.title}
                            </h2>
                            <div ref={flowRef} className="mt-12">
                                <ol className="grid gap-12 lg:grid-cols-3 lg:gap-10">
                                    {service.flow.steps.map((step, index) => (
                                        <li
                                            key={step.title}
                                            data-stagger
                                            className="group border-t border-foreground/40 pt-6"
                                        >
                                            <Numeral
                                                value={index + 1}
                                                className="text-8xl lg:text-9xl"
                                            />
                                            <h3 className="mt-6 text-2xl font-medium tracking-tight">
                                                {step.title}
                                            </h3>
                                            <p className="mt-3 max-w-xs text-base leading-relaxed text-foreground/75">
                                                {step.body}
                                            </p>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </Container>
                    </section>
                )}

                {service.catalogue && (
                    <section
                        id="range"
                        aria-labelledby="range-heading"
                        className={`dark scroll-mt-24 bg-brand-forest text-foreground ${pad}`}
                    >
                        <Container>
                            <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr] lg:items-end lg:gap-16">
                                <h2
                                    id="range-heading"
                                    className="text-4xl leading-none font-medium tracking-tighter text-balance sm:text-6xl"
                                >
                                    {service.catalogue.title}
                                </h2>
                                <p className="max-w-md text-base leading-relaxed text-foreground/80">
                                    {service.catalogue.intro}
                                </p>
                            </div>
                            <div ref={rangeRef} className="mt-12">
                                <div className="grid gap-16">
                                    {service.catalogue.groups.map(
                                        (group, index) => (
                                            <div
                                                key={group.title}
                                                data-stagger
                                                className="group border-t border-foreground/40 pt-5"
                                            >
                                                <div className="flex items-baseline gap-5">
                                                    <Numeral
                                                        value={index + 1}
                                                        className="text-5xl"
                                                    />
                                                    <h3 className="text-2xl font-medium tracking-tight">
                                                        {group.title}
                                                    </h3>
                                                </div>
                                                <ul className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                                                    {group.models.map(
                                                        (model) => (
                                                            <li
                                                                key={model.name}
                                                            >
                                                                <figure className="overflow-hidden rounded-xl bg-foreground">
                                                                    <img
                                                                        src={`/images/locks/${model.image}`}
                                                                        alt={`${model.name} door lock`}
                                                                        width={
                                                                            650
                                                                        }
                                                                        height={
                                                                            550
                                                                        }
                                                                        loading="lazy"
                                                                        className="h-auto w-full mix-blend-multiply"
                                                                    />
                                                                    <figcaption className="px-4 pb-3 text-sm font-medium text-background">
                                                                        {
                                                                            model.name
                                                                        }
                                                                    </figcaption>
                                                                </figure>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </div>
                                        ),
                                    )}
                                </div>
                            </div>
                            <p className="mt-12 max-w-xl text-sm text-foreground/75">
                                Product photos: Est.
                            </p>
                        </Container>
                    </section>
                )}

                <section
                    id="faq"
                    aria-labelledby="faq-heading"
                    className={`scroll-mt-24 ${pad}`}
                >
                    <Container>
                        <div className="grid gap-10 lg:grid-cols-[0.8fr_1.2fr] lg:gap-20">
                            <h2
                                id="faq-heading"
                                className="text-4xl leading-none font-medium tracking-tighter text-balance sm:text-6xl lg:sticky lg:top-28 lg:self-start"
                            >
                                {service.name} questions
                            </h2>
                            <div className="border-t border-foreground">
                                {service.faqs.map((faq, index) => (
                                    <details
                                        key={faq.q}
                                        name="service-faq"
                                        open={index === 0}
                                        className="group border-b border-border"
                                    >
                                        <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-6 py-6 text-xl font-medium tracking-tight transition-colors group-open:text-primary hover:text-primary focus-visible:outline-ring sm:text-2xl [&::-webkit-details-marker]:hidden">
                                            <h3 className="text-balance">
                                                {faq.q}
                                            </h3>
                                            <span
                                                aria-hidden="true"
                                                className="text-3xl leading-none font-normal transition-transform duration-200 group-open:rotate-45 motion-reduce:transition-none"
                                            >
                                                +
                                            </span>
                                        </summary>
                                        <p className="max-w-xl pr-10 pb-7 text-base leading-relaxed text-foreground/70">
                                            {faq.a}
                                        </p>
                                    </details>
                                ))}
                            </div>
                        </div>
                    </Container>
                </section>

                <section
                    aria-labelledby="more-heading"
                    className={`bg-muted ${pad}`}
                >
                    <Container>
                        <h2
                            id="more-heading"
                            className="text-sm font-medium text-foreground/70"
                        >
                            More Estatell services
                        </h2>
                        <ul className="mt-6 border-b border-foreground">
                            {others.map((item) => (
                                <li
                                    key={item.slug}
                                    className="border-t border-foreground"
                                >
                                    <a
                                        href={`/services/${item.slug}`}
                                        className="group grid min-h-11 grid-cols-[1fr_auto] items-center gap-x-6 py-6 focus-visible:outline-ring sm:py-8 md:grid-cols-[1.3fr_1fr_auto]"
                                    >
                                        <span className="text-4xl leading-none font-medium tracking-tighter transition-colors group-hover:text-primary sm:text-6xl">
                                            {item.name}
                                        </span>
                                        <span className="hidden max-w-xs text-sm leading-relaxed text-foreground/70 md:block">
                                            {item.blurb}
                                        </span>
                                        <ArrowUpRight
                                            className="size-7 shrink-0 transition-transform duration-300 group-hover:translate-x-1 group-hover:-translate-y-1 motion-reduce:transition-none sm:size-10"
                                            aria-hidden="true"
                                        />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </Container>
                </section>

                <Closing />
            </main>
        </>
    );
}
