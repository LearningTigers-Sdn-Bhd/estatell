/**
 * Fills a signup message template. The server owns the template; the link holds no secret, so the browser fills it.
 */
export function fillSignupMessage(
    template: string,
    partnerName: string,
    signupUrl: string,
): string {
    return template
        .replaceAll('{partner}', partnerName)
        .replaceAll('{link}', signupUrl);
}
