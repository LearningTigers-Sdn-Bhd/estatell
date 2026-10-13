/**
 * Suggests a property code prefix from a partner name: the first letters of the first two words, or the first two
 * letters of a single word, in capitals. "Andaman Stays" gives "AS". Empty until the name has two letters.
 * The admin can change it, and the server checks that it is free.
 */
export function suggestPartnerPrefix(name: string): string {
    const words = name
        .split(/\s+/)
        .map((word) => word.replace(/[^\p{L}]/gu, ''))
        .filter((word) => word !== '');

    const letters =
        words.length >= 2
            ? `${words[0][0]}${words[1][0]}`
            : (words[0]?.slice(0, 2) ?? '');

    const prefix = letters.normalize('NFD').replace(/[^A-Za-z]/g, '');

    return prefix.length === 2 ? prefix.toUpperCase() : '';
}
