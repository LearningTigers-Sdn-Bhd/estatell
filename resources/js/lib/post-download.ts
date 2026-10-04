/**
 * POSTs JSON to a download route and saves the file the server sends back. Laravel's XSRF cookie goes in the
 * `X-XSRF-TOKEN` header, as Inertia does. Returns the response headers on success. On a 422 it throws the first
 * validation message, otherwise a generic one.
 */
export async function postDownload(
    url: string,
    body: Record<string, unknown>,
): Promise<Headers> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const data = (await response.json().catch(() => null)) as {
            errors?: Record<string, string[]>;
        } | null;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;

        throw new Error(first ?? 'The file could not be made.');
    }

    const name =
        /filename="?([^";]+)"?/.exec(
            response.headers.get('Content-Disposition') ?? '',
        )?.[1] ?? 'download';
    const href = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');

    link.href = href;
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(href);

    return response.headers;
}
