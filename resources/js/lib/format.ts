export function formatMoney(minor: number | null | undefined, currency: string, exponent = 2): string {
    if (minor === null || minor === undefined) return '—';
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            minimumFractionDigits: exponent,
            maximumFractionDigits: exponent,
        }).format(minor / 10 ** exponent);
    } catch {
        return `${currency} ${(minor / 10 ** exponent).toFixed(exponent)}`;
    }
}

/** Parse "YYYY-MM-DD" as a local calendar date (no timezone shifting). */
export function parseDate(iso: string): Date {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
}

export function formatDate(
    iso: string,
    opts: Intl.DateTimeFormatOptions = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' },
): string {
    return new Intl.DateTimeFormat(undefined, opts).format(parseDate(iso));
}

export function formatRange(start: string, end: string): string {
    if (start === end) return formatDate(start);
    return `${formatDate(start, { day: 'numeric', month: 'short' })} – ${formatDate(end)}`;
}

export function formatDuration(minutes: number): string {
    if (minutes < 60) return `${minutes} min`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m ? `${h} h ${m} min` : `${h} h`;
}

export function dayCount(start: string, end: string): number {
    const ms = parseDate(end).getTime() - parseDate(start).getTime();
    return Math.round(ms / 86_400_000) + 1;
}

export function addMinutes(hhmm: string, minutes: number): string {
    const [h, m] = hhmm.split(':').map(Number);
    const total = h * 60 + m + minutes;
    return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

export function timeAgo(iso: string): string {
    const diff = (new Date(iso).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    for (const [unit, secs] of units) {
        if (Math.abs(diff) >= secs) return rtf.format(Math.round(diff / secs), unit);
    }
    return 'just now';
}

export const statusLabels: Record<string, string> = {
    upcoming: 'Upcoming',
    in_progress: 'In progress',
    completed: 'Completed',
};

/** True for http(s) URLs only; used before rendering any external link. */
export function isSafeUrl(url: string | null | undefined): url is string {
    if (!url) return false;
    try {
        const u = new URL(url);
        return u.protocol === 'https:' || u.protocol === 'http:';
    } catch {
        return false;
    }
}
