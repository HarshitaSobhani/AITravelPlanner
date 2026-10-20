<script setup lang="ts">
import { categoryColor } from '@/lib/categories';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

export interface MapPoint {
    key: string | number;
    name: string;
    latitude: number;
    longitude: number;
    category?: string | null;
    label?: string | number; // shown inside the marker (e.g. stop order)
    detail?: string | null;
}

const props = withDefaults(
    defineProps<{
        points: MapPoint[];
        selectedKey?: string | number | null;
        route?: [number, number][] | null; // only ever real route geometry from a routing provider
        center: [number, number];
        tileUrl: string;
        attribution: string;
        height?: string;
        emptyMessage?: string;
        ariaLabel?: string;
    }>(),
    { selectedKey: null, route: null, height: '100%', emptyMessage: 'No places with stored coordinates to show yet.', ariaLabel: 'Map of places' },
);
const emit = defineEmits<{ (e: 'select', key: string | number): void }>();

const el = ref<HTMLDivElement | null>(null);
const tileError = ref(false);
let map: L.Map | null = null;
let markers = new Map<string | number, L.Marker>();
let layer: L.LayerGroup | null = null;
let routeLayer: L.Polyline | null = null;
let ro: ResizeObserver | null = null;

function icon(p: MapPoint, selected: boolean) {
    const div = document.createElement('div');
    div.className = 'rw-marker' + (selected ? ' sel' : '');
    div.style.background = categoryColor(p.category ?? null);
    div.textContent = p.label !== undefined ? String(p.label) : '';
    return L.divIcon({ html: div, className: '', iconSize: [28, 28], iconAnchor: [14, 14], popupAnchor: [0, -14] });
}

function popup(p: MapPoint) {
    // Built with DOM APIs and textContent: place names are untrusted and must never be parsed as HTML.
    const root = document.createElement('div');
    const title = document.createElement('strong');
    title.textContent = p.name;
    root.appendChild(title);
    if (p.detail) {
        const d = document.createElement('div');
        d.textContent = p.detail;
        d.style.marginTop = '2px';
        root.appendChild(d);
    }
    const link = document.createElement('a');
    link.href = `https://www.openstreetmap.org/?mlat=${p.latitude}&mlon=${p.longitude}#map=17/${p.latitude}/${p.longitude}`;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = 'Open in OpenStreetMap';
    link.style.display = 'block';
    link.style.marginTop = '4px';
    root.appendChild(link);
    return root;
}

function render(fit = true) {
    if (!map || !layer) return;
    layer.clearLayers();
    markers = new Map();
    const valid = props.points.filter(
        (p) => Number.isFinite(p.latitude) && Number.isFinite(p.longitude) && Math.abs(p.latitude) <= 90 && Math.abs(p.longitude) <= 180,
    );
    for (const p of valid) {
        const m = L.marker([p.latitude, p.longitude], {
            icon: icon(p, p.key === props.selectedKey),
            title: p.name,
            alt: p.name,
            keyboard: true,
            riseOnHover: true,
        });
        m.bindPopup(() => popup(p));
        m.on('click', () => emit('select', p.key));
        m.addTo(layer);
        markers.set(p.key, m);
    }
    if (fit) {
        if (valid.length > 1)
            map.fitBounds(L.latLngBounds(valid.map((p) => [p.latitude, p.longitude] as [number, number])), { padding: [32, 32], maxZoom: 15 });
        else if (valid.length === 1) map.setView([valid[0].latitude, valid[0].longitude], 14);
        else map.setView(props.center, 11);
    }
}

function renderRoute() {
    if (!map) return;
    routeLayer?.remove();
    routeLayer = null;
    if (props.route && props.route.length > 1) {
        routeLayer = L.polyline(props.route, { color: '#216560', weight: 4, opacity: 0.85 }).addTo(map);
    }
}

function highlight() {
    for (const p of props.points) {
        const m = markers.get(p.key);
        m?.setIcon(icon(p, p.key === props.selectedKey));
    }
    if (props.selectedKey !== null) {
        const m = markers.get(props.selectedKey);
        if (m && map) {
            map.panTo(m.getLatLng(), { animate: true });
            m.openPopup();
        }
    }
}

onMounted(() => {
    if (!el.value) return;
    map = L.map(el.value, { scrollWheelZoom: false, zoomControl: true }).setView(props.center, 11);
    const tiles = L.tileLayer(props.tileUrl, { attribution: props.attribution, maxZoom: 19 }).addTo(map);
    tiles.on('tileerror', () => (tileError.value = true));
    tiles.on('tileload', () => (tileError.value = false));
    layer = L.layerGroup().addTo(map);
    render();
    renderRoute();
    ro = new ResizeObserver(() => map?.invalidateSize());
    ro.observe(el.value);
});

onBeforeUnmount(() => {
    ro?.disconnect();
    map?.remove();
    map = null;
});

watch(
    () => props.points,
    () => render(true),
    { deep: true },
);
watch(() => props.route, renderRoute);
watch(() => props.selectedKey, highlight);

defineExpose({ invalidate: () => map?.invalidateSize() });
</script>

<template>
    <div class="relative overflow-hidden rounded-2xl border border-border" :style="{ height }">
        <div ref="el" class="h-full w-full" role="application" :aria-label="ariaLabel" />
        <div
            v-if="!points.length"
            class="pointer-events-none absolute inset-x-4 top-4 z-[500] rounded-lg bg-card/95 px-3 py-2 text-sm shadow"
            role="status"
        >
            {{ emptyMessage }}
        </div>
        <div v-if="tileError" class="absolute inset-x-4 bottom-8 z-[500] rounded-lg bg-card/95 px-3 py-2 text-sm text-terra-600 shadow" role="alert">
            Map tiles could not be loaded. Markers and the list still show your stops.
        </div>
    </div>
</template>
