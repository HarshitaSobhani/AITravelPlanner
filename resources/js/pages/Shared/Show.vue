<script setup lang="ts">
import Logo from '@/components/rw/Logo.vue';
import TripMap, { type MapPoint } from '@/components/rw/TripMap.vue';
import ItineraryBoard from '@/components/trip/ItineraryBoard.vue';
import { formatDuration, formatMoney, formatRange } from '@/lib/format';
import type { Day, MapConfig, Stop, TripData } from '@/types/roam';
import { Head, Link } from '@inertiajs/vue3';
import { Info, List, Map as MapIcon } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    trip: TripData;
    days: Day[];
    budget: { currency: string; exponent: number; estimated_minor: number; budget_minor: number | null } | null;
    map: MapConfig;
}>();

const selected = ref<number | null>(null);
const activeDay = ref<number | null>(null);
const view = ref<'list' | 'map'>('list');

const points = computed<MapPoint[]>(() =>
    props.days
        .filter((d) => !activeDay.value || d.id === activeDay.value)
        .flatMap((d) =>
            d.stops
                .filter((s) => s.latitude !== null && s.longitude !== null)
                .map((s, i) => ({
                    key: s.id,
                    name: s.name,
                    latitude: s.latitude as number,
                    longitude: s.longitude as number,
                    category: s.category,
                    label: activeDay.value ? i + 1 : `${d.position + 1}.${i + 1}`,
                    detail: `Day ${d.position + 1}${s.start_time ? ' · ' + s.start_time : ''} · ${formatDuration(s.duration_minutes)}`,
                })),
        ),
);
function pick(s: Stop) {
    selected.value = s.id;
    view.value = 'map';
}
</script>

<template>
    <Head :title="`${trip.title} (shared itinerary)`" />
    <div class="min-h-svh bg-background text-foreground">
        <header class="border-b border-border">
            <div class="container-rw flex h-14 items-center justify-between">
                <Link :href="route('home')" aria-label="RoamWise home"><Logo /></Link>
                <span class="chip">Read-only shared itinerary</span>
            </div>
        </header>
        <main id="main" class="container-rw py-8">
            <h1 class="text-3xl font-semibold sm:text-4xl">{{ trip.title }}</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ trip.destination.name }}<template v-if="trip.destination.country_name">, {{ trip.destination.country_name }}</template> ·
                {{ formatRange(trip.start_date, trip.end_date) }} · {{ trip.day_count }} {{ trip.day_count === 1 ? 'day' : 'days' }}
            </p>
            <p class="mt-4 flex items-start gap-2 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:bg-brand-700/20 dark:text-brand-100">
                <Info class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span
                    >A suggested schedule shared by its owner. Times are suggestions, not bookings, and opening hours are not verified. Anyone with
                    this link can view it.</span
                >
            </p>
            <p v-if="budget" class="mt-3 text-sm">
                <strong>Estimated total (owner's figures):</strong> {{ formatMoney(budget.estimated_minor, budget.currency, budget.exponent) }}
            </p>

            <div class="mt-5 flex gap-2 lg:hidden" role="tablist" aria-label="List or map">
                <button
                    type="button"
                    role="tab"
                    class="btn-outline flex-1"
                    :aria-selected="view === 'list'"
                    :class="view === 'list' ? '!bg-primary !text-primary-foreground' : ''"
                    @click="view = 'list'"
                >
                    <List class="h-4 w-4" aria-hidden="true" /> Itinerary
                </button>
                <button
                    type="button"
                    role="tab"
                    class="btn-outline flex-1"
                    :aria-selected="view === 'map'"
                    :class="view === 'map' ? '!bg-primary !text-primary-foreground' : ''"
                    @click="view = 'map'"
                >
                    <MapIcon class="h-4 w-4" aria-hidden="true" /> Map
                </button>
            </div>

            <div class="mt-4 grid gap-5 lg:grid-cols-2">
                <div :class="view === 'map' ? 'hidden lg:block' : ''">
                    <ItineraryBoard
                        :days="days"
                        :selected-stop-id="selected"
                        :active-day-id="activeDay"
                        readonly
                        @select="pick"
                        @select-day="(id) => (activeDay = activeDay === id ? null : id)"
                    />
                </div>
                <div :class="view === 'list' ? 'hidden lg:block' : ''" class="h-[26rem] sm:h-[32rem] lg:sticky lg:top-6 lg:h-[36rem] lg:self-start">
                    <TripMap
                        :points="points"
                        :selected-key="selected"
                        :center="[trip.destination.latitude, trip.destination.longitude]"
                        :tile-url="map.tile_url"
                        :attribution="map.attribution"
                        aria-label="Map of shared stops"
                        @select="(k) => (selected = Number(k))"
                    />
                </div>
            </div>
            <p class="mt-10 text-xs text-muted-foreground">
                Map © OpenStreetMap contributors. <template v-if="trip.source_attribution">{{ trip.source_attribution }}</template> Made with
                RoamWise.
            </p>
        </main>
    </div>
</template>
