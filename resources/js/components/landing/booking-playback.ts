export function bookingPlaybackDelay(
    now: number,
    resumeAt: number,
    canPlay: boolean,
) {
    if (!canPlay) return null;
    return Math.max(8_000, resumeAt - now);
}
