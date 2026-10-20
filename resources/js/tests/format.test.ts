import { artGradient, categoryColor, categoryLabel } from '@/lib/categories';
import { addMinutes, dayCount, formatDuration, formatMoney, isSafeUrl, parseDate } from '@/lib/format';
import { describe, expect, it } from 'vitest';

describe('format helpers', () => {
    it('formats minor units with the currency exponent', () => {
        expect(formatMoney(150050, 'EUR', 2)).toMatch(/1,?500\.50/);
        expect(formatMoney(150000, 'JPY', 0)).toMatch(/150,?000/);
        expect(formatMoney(null, 'EUR')).toBe('—');
    });

    it('falls back gracefully for an unknown currency code', () => {
        expect(formatMoney(1234, 'ZZZZ!', 2)).toContain('12.34');
    });

    it('counts inclusive trip days', () => {
        expect(dayCount('2030-05-01', '2030-05-01')).toBe(1);
        expect(dayCount('2030-05-01', '2030-05-10')).toBe(10);
        expect(dayCount('2030-02-27', '2030-03-02')).toBe(4);
    });

    it('parses dates as local calendar days', () => {
        const d = parseDate('2030-05-06');
        expect([d.getFullYear(), d.getMonth(), d.getDate()]).toEqual([2030, 4, 6]);
    });

    it('adds minutes across hours', () => {
        expect(addMinutes('09:30', 90)).toBe('11:00');
        expect(addMinutes('23:30', 60)).toBe('00:30');
    });

    it('formats durations', () => {
        expect(formatDuration(45)).toBe('45 min');
        expect(formatDuration(120)).toBe('2 h');
        expect(formatDuration(150)).toBe('2 h 30 min');
    });

    it('only treats http(s) URLs as safe links', () => {
        expect(isSafeUrl('https://example.org/x')).toBe(true);
        expect(isSafeUrl('javascript:alert(1)')).toBe(false);
        expect(isSafeUrl('data:text/html,hi')).toBe(false);
        expect(isSafeUrl(null)).toBe(false);
        expect(isSafeUrl('not a url')).toBe(false);
    });
});

describe('category helpers', () => {
    it('labels unknown categories without crashing', () => {
        expect(categoryLabel('museum')).toBe('Museums');
        expect(categoryLabel('zoo')).toBe('Zoo');
        expect(categoryLabel(null)).toBe('Custom stop');
        expect(categoryColor('nope')).toMatch(/^#/);
    });

    it('makes a stable gradient per name', () => {
        expect(artGradient('Paris')).toBe(artGradient('Paris'));
        expect(artGradient('Paris')).not.toBe(artGradient('Kyoto'));
    });
});
