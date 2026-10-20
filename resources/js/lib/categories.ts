export const categoryMeta: Record<string, { label: string; color: string }> = {
    landmark: { label: 'Landmarks', color: '#1f5f5b' },
    museum: { label: 'Museums', color: '#a84e2f' },
    park: { label: 'Parks & nature', color: '#46603c' },
    market: { label: 'Markets', color: '#b7791f' },
    neighbourhood: { label: 'Neighbourhoods', color: '#6b4c8a' },
    bookshop: { label: 'Bookshops', color: '#8a3b5c' },
    shopping: { label: 'Shopping', color: '#2f5d8a' },
};

export function categoryLabel(key: string | null): string {
    if (!key) return 'Custom stop';
    return categoryMeta[key]?.label ?? key.charAt(0).toUpperCase() + key.slice(1);
}

export function categoryColor(key: string | null): string {
    return (key && categoryMeta[key]?.color) || '#5d594f';
}

/** Deterministic gradient used where no licensed photo is stored. */
export function artGradient(seed: string): string {
    let h = 0;
    for (const c of seed) h = (h * 31 + c.charCodeAt(0)) % 360;
    const a = 160 + (h % 60);
    const b = 10 + (h % 30);
    return `linear-gradient(135deg, hsl(${a} 38% 32%) 0%, hsl(${a + 20} 34% 46%) 55%, hsl(${b} 55% 58%) 100%)`;
}
