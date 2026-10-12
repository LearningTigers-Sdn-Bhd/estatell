/** The charge code suggested from a name: upper case, `_` between words, at most 20 characters. */
export function suggestCode(name: string): string {
    return name
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 20)
        .replace(/_+$/, '');
}
