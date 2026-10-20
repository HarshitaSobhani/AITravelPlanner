import TripMap from '@/components/rw/TripMap.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

class RO {
    observe() {}
    disconnect() {}
}
vi.stubGlobal('ResizeObserver', RO);

const base = {
    center: [48.85, 2.35] as [number, number],
    tileUrl: 'https://tile.example.test/{z}/{x}/{y}.png',
    attribution: '© OpenStreetMap contributors',
};

describe('TripMap', () => {
    it('renders one marker per point with valid coordinates and skips invalid ones', () => {
        const w = mount(TripMap, {
            attachTo: document.body,
            props: {
                ...base,
                points: [
                    { key: 1, name: 'A', latitude: 48.85, longitude: 2.35, label: 1 },
                    { key: 2, name: 'B', latitude: 48.86, longitude: 2.36, label: 2 },
                    { key: 3, name: 'Bad', latitude: 999, longitude: 2.36, label: 3 },
                    { key: 4, name: 'NaN', latitude: Number.NaN, longitude: 2.36, label: 4 },
                ],
            },
        });
        expect(w.findAll('.rw-marker')).toHaveLength(2);
        expect(w.text()).not.toContain('No places');
        w.unmount();
    });

    it('shows an empty state when there are no points', () => {
        const w = mount(TripMap, { attachTo: document.body, props: { ...base, points: [], emptyMessage: 'Nothing to show' } });
        expect(w.text()).toContain('Nothing to show');
        expect(w.findAll('.rw-marker')).toHaveLength(0);
        w.unmount();
    });

    it('emits select when a marker is clicked and never injects place names as HTML', async () => {
        const evil = '<img src=x onerror=alert(1)>';
        const w = mount(TripMap, {
            attachTo: document.body,
            props: { ...base, points: [{ key: 7, name: evil, latitude: 48.85, longitude: 2.35, label: 1 }] },
        });
        const marker = w.find('.rw-marker');
        await marker.trigger('click');
        expect(w.emitted('select')?.[0]).toEqual([7]);
        expect(document.querySelector('img[src="x"]')).toBeNull();
        expect(document.body.textContent).toContain(evil);
        w.unmount();
    });

    it('draws a route polyline only when route geometry is supplied', async () => {
        const w = mount(TripMap, {
            attachTo: document.body,
            props: { ...base, points: [{ key: 1, name: 'A', latitude: 48.85, longitude: 2.35 }], route: null },
        });
        expect(w.element.querySelectorAll('path.leaflet-interactive')).toHaveLength(0);
        await w.setProps({
            route: [
                [48.85, 2.35],
                [48.86, 2.36],
            ],
        });
        expect(w.element.querySelectorAll('path.leaflet-interactive').length).toBeGreaterThan(0);
        w.unmount();
    });
});
