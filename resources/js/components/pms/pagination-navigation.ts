export function paginationPage(value: string, lastPage: number): number | null {
    if (!/^\d+$/.test(value)) {
        return null;
    }

    const page = Number(value);

    return Number.isSafeInteger(page) && page >= 1 && page <= lastPage
        ? page
        : null;
}

export function paginationUrl(baseUrl: string, page: number): string {
    const url = new URL(baseUrl);
    url.searchParams.set('page', String(page));

    return url.href;
}
