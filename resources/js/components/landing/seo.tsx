import { Head, usePage } from '@inertiajs/react';

type Image = { src: string; width: number; height: number };

const defaultImage: Image = {
    src: '/images/landing/boutique-hotel-lobby.webp',
    width: 1672,
    height: 941,
};

export function useSiteUrl() {
    return usePage().props.siteUrl;
}

/** The one Organization node every public page points at, so crawlers and answer engines resolve "Estatell" to a single entity. */
export function organizationNode(siteUrl: string) {
    return {
        '@type': 'Organization',
        '@id': `${siteUrl}/#organization`,
        name: 'Estatell',
        url: siteUrl,
        logo: `${siteUrl}/images/landing/estatell-logo.webp`,
        email: 'hello@estatell.com',
    };
}

type PageMetaProps = {
    /** Page title. The app appends its name, so leave the brand out. */
    title: string;
    description: string;
    /** Path of this page, for the canonical and og:url. */
    path: string;
    image?: Image;
    /** JSON-LD nodes for this page. The site Organization is always included. */
    nodes?: object[];
};

/** Title, description, canonical, Open Graph, Twitter card and JSON-LD for a public page. */
export function PageMeta({
    title,
    description,
    path,
    image = defaultImage,
    nodes = [],
}: PageMetaProps) {
    const siteUrl = useSiteUrl();
    const url = `${siteUrl}${path}`;
    const imageUrl = `${siteUrl}${image.src}`;
    const graph = {
        '@context': 'https://schema.org',
        '@graph': [organizationNode(siteUrl), ...nodes],
    };

    return (
        <Head title={title}>
            <meta name="description" content={description} />
            <link rel="canonical" href={url} />
            <meta property="og:type" content="website" />
            <meta property="og:site_name" content="Estatell" />
            <meta property="og:title" content={`${title} | Estatell`} />
            <meta property="og:description" content={description} />
            <meta property="og:url" content={url} />
            <meta property="og:image" content={imageUrl} />
            <meta property="og:image:width" content={String(image.width)} />
            <meta property="og:image:height" content={String(image.height)} />
            <meta name="twitter:card" content="summary_large_image" />
            <script type="application/ld+json">
                {JSON.stringify(graph).replace(/</g, '\\u003c')}
            </script>
        </Head>
    );
}
