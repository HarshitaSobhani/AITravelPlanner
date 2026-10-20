<script setup lang="ts">
import ConfirmDialog from '@/components/rw/ConfirmDialog.vue';
import TripMap, { type MapPoint } from '@/components/rw/TripMap.vue';
import AddStopDialog from '@/components/trip/AddStopDialog.vue';
import BudgetPanel from '@/components/trip/BudgetPanel.vue';
import DetailsForm from '@/components/trip/DetailsForm.vue';
import ItineraryBoard from '@/components/trip/ItineraryBoard.vue';
import SharePanel from '@/components/trip/SharePanel.vue';
import WeatherPanel from '@/components/trip/WeatherPanel.vue';
import RoamLayout from '@/layouts/RoamLayout.vue';
import { categoryColor, categoryLabel } from '@/lib/categories';
import { formatDuration, formatRange, statusLabels } from '@/lib/format';
import type { Budget, Day, Expense, MapConfig, Option, Pace, Place, Share, Stop, TripData } from '@/types/roam';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ExternalLink, Info, List, Map as MapIcon, Printer, RefreshCw, Sparkles } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{
    trip: TripData;
    days: Day[];
    budget: Budget;
    categories: { id: number; key: string; name: string }[];
    expenses: Expense[];
    shares: Share[];
    currencies: string[];
    interestOptions: Option[];
    paces: Pace[];
    places: Place[];
    weatherEnabled: boolean;
    routingEnabled: boolean;
    aiAvailable: boolean;
    map: MapConfig;
    maxDays: number;
}>();

const tabs = [
    { key: 'itinerary', label: 'Itinerary' },
    { key: 'budget', label: 'Budget' },
    { key: 'weather', label: 'Weather' },
    { key: 'details', label: 'Trip details' },
    { key: 'share', label: 'Share' },
] as const;
type TabKey = (typeof tabs)[number]['key'];

function initialTab(): TabKey {
    const h = typeof window !== 'undefined' ? window.location.hash.slice(1) : '';
    return (tabs.find((t) => t.key === h)?.key ?? 'itinerary') as TabKey;
}
const tab = ref<TabKey>(initialTab());
function setTab(k: TabKey) {
    tab.value = k;
    history.replaceState(history.state, '', `#${k}`);
}
function onTabKey(e: KeyboardEvent, i: number) {
    const dir = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
    if (!dir) return;
    const n = (i + dir + tabs.length) % tabs.length;
    setTab(tabs[n].key);
    nextTick(() => document.getElementById(`tab-${tabs[n].key}`)?.focus());
}

// --- itinerary + map state ---
const selectedStopId = ref<number | null>(null);
const activeDayId = ref<number | null>(null);
const catFilter = ref('');
const mobileView = ref<'list' | 'map'>('list');
const mapRef = ref<InstanceType<typeof TripMap> | null>(null);
const addOpen = ref(false);
const addDayId = ref<number | null>(null);

const allStops = computed(() =>
    props.days.flatMap((d) => d.stops.map((s) => ({ ...s, dayId: d.id, dayIndex: d.position, order: d.stops.indexOf(s) + 1 }))),
);
const mappable = computed(() => allStops.value.filter((s) => s.latitude !== null && s.longitude !== null));
const unmapped = computed(() => allStops.value.length - mappable.value.length);
const stopCategories = computed(() => [...new Set(allStops.value.map((s) => s.category).filter(Boolean))] as string[]);

const points = computed<MapPoint[]>(() =>
    mappable.value
        .filter((s) => (!activeDayId.value || s.dayId === activeDayId.value) && (!catFilter.value || s.category === catFilter.value))
        .map((s) => ({
            key: s.id,
            name: s.name,
            latitude: s.latitude as number,
            longitude: s.longitude as number,
            category: s.category,
            label: activeDayId.value ? s.order : `${s.dayIndex + 1}.${s.order}`,
            detail: `Day ${s.dayIndex + 1}${s.start_time ? ' · ' + s.start_time : ''} · ${formatDuration(s.duration_minutes)}`,
        })),
);

function selectStop(s: Stop) {
    selectedStopId.value = s.id;
    if (s.latitude !== null) mobileView.value = 'map';
}
function onMapSelect(key: string | number) {
    selectedStopId.value = Number(key);
    document
        .getElementById(`day-body-${allStops.value.find((s) => s.id === Number(key))?.dayId}`)
        ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
function openAdd(dayId: number | null = null) {
    addDayId.value = dayId;
    addOpen.value = true;
}
watch(mobileView, async (v) => {
    if (v === 'map') {
        await nextTick();
        setTimeout(() => mapRef.value?.invalidate(), 60);
    }
});
watch(tab, async (t) => {
    if (t === 'itinerary') {
        await nextTick();
        setTimeout(() => mapRef.value?.invalidate(), 60);
    }
});

// --- route (only real geometry from a configured routing provider) ---
const routeState = ref<{
    status: string;
    message?: string;
    geometry?: [number, number][];
    distance_m?: number;
    duration_s?: number;
    directions_url?: string | null;
    attribution?: string;
} | null>(null);
const routeLoading = ref(false);
const routeLine = computed(() => (routeState.value?.status === 'ok' && routeState.value.geometry ? routeState.value.geometry : null));
watch(activeDayId, () => (routeState.value = null));

async function loadRoute(profile: 'foot' | 'driving') {
    if (!activeDayId.value) return;
    routeLoading.value = true;
    try {
        const res = await fetch(route('trips.route', { trip: props.trip.id, day: activeDayId.value, profile }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        routeState.value = res.ok ? await res.json() : { status: 'error', message: 'Route lookup failed.' };
    } catch {
        routeState.value = { status: 'error', message: 'Route lookup failed. Check your connection.' };
    } finally {
        routeLoading.value = false;
    }
}

// --- regenerate ---
const regenOpen = ref(false);
const regenMode = ref<'fill' | 'replace'>('fill');
const regenBusy = ref(false);
const untouched = computed(() => allStops.value.filter((s) => s.source === 'generated' && !s.is_locked).length);
const protectedCount = computed(() => allStops.value.length - untouched.value);
function regenerate() {
    regenBusy.value = true;
    router.post(route('trips.regenerate', props.trip.id), regenMode.value === 'replace' ? { replace: 1 } : {}, {
        preserveScroll: true, preserveState: true,
        onFinish: () => {
            regenBusy.value = false;
            regenOpen.value = false;
        },
    });
}

const summaryBusy = ref(false);
function writeSummary() {
    summaryBusy.value = true;
    router.post(route('trips.summary', props.trip.id), {}, { preserveScroll: true, preserveState: true, onFinish: () => (summaryBusy.value = false) });
}

const deleteOpen = ref(false);
const delForm = useForm({});
function destroyTrip() {
    delForm.delete(route('trips.destroy', props.trip.id));
}

const totalStops = computed(() => allStops.value.length);
const hasEmptyPlan = computed(() => totalStops.value === 0);
const statusClass: Record<string, string> = {
    upcoming: 'bg-brand-50 text-brand-700 dark:bg-brand-700/30 dark:text-brand-100',
    in_progress: 'bg-terra-100 text-terra-600',
    completed: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head :title="trip.title" />
    <RoamLayout :footer="false">
        <div class="container-rw py-6">
            <nav class="text-sm text-muted-foreground" aria-label="Breadcrumb">
                <Link :href="route('trips.index')" class="hover:underline">My trips</Link> / <span aria-current="page">{{ trip.title }}</span>
            </nav>
            <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-3xl font-semibold sm:text-4xl">{{ trip.title }}</h1>
                    <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                        <Link :href="route('destinations.show', trip.destination.slug)" class="font-medium text-foreground hover:underline"
                            >{{ trip.destination.name
                            }}<template v-if="trip.destination.country_name">, {{ trip.destination.country_name }}</template></Link
                        >
                        <span>{{ formatRange(trip.start_date, trip.end_date) }}</span>
                        <span>{{ trip.day_count }} {{ trip.day_count === 1 ? 'day' : 'days' }}</span>
                        <span>{{ trip.travelers }} {{ trip.travelers === 1 ? 'traveller' : 'travellers' }}</span>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusClass[trip.status]">{{
                            statusLabels[trip.status]
                        }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a :href="route('trips.export', trip.id)" target="_blank" rel="noopener" class="btn-outline btn-sm"
                        ><Printer class="h-3.5 w-3.5" aria-hidden="true" /> Print / PDF</a
                    >
                    <button type="button" class="btn-danger btn-sm" @click="deleteOpen = true">Delete trip</button>
                </div>
            </div>

            <div class="mt-5 border-b border-border" role="tablist" aria-label="Trip sections">
                <div class="-mb-px flex overflow-x-auto">
                    <button
                        v-for="(t, i) in tabs"
                        :id="`tab-${t.key}`"
                        :key="t.key"
                        type="button"
                        role="tab"
                        class="tab whitespace-nowrap"
                        :aria-selected="tab === t.key"
                        :aria-controls="`panel-${t.key}`"
                        :tabindex="tab === t.key ? 0 : -1"
                        @click="setTab(t.key)"
                        @keydown="onTabKey($event, i)"
                    >
                        {{ t.label }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Itinerary -->
        <div v-show="tab === 'itinerary'" id="panel-itinerary" role="tabpanel" aria-labelledby="tab-itinerary" class="container-rw pb-12">
            <div class="mb-4 space-y-2">
                <p class="flex items-start gap-2 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 dark:bg-brand-700/20 dark:text-brand-100">
                    <Info class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span
                        ><strong>Suggested schedule.</strong> Times and stops are suggestions you can edit. Travel times are straight-line estimates.
                        Opening hours and availability are not checked unless stated, so confirm with each venue.</span
                    >
                </p>
                <ul
                    v-if="trip.warnings.length"
                    class="space-y-1 rounded-xl border border-terra-400/60 bg-terra-100/60 px-4 py-3 text-sm text-terra-600 dark:bg-terra-600/10 dark:text-terra-100"
                >
                    <li v-for="w in trip.warnings" :key="w.code" class="flex items-start gap-2">
                        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ w.message }}
                    </li>
                </ul>
                <div v-if="trip.summary" class="panel p-4 text-sm">
                    <p class="mb-1 flex items-center gap-1 text-xs font-semibold text-terra-600">
                        <Sparkles class="h-3.5 w-3.5" aria-hidden="true" /> AI-written overview of your saved stops
                    </p>
                    <p class="whitespace-pre-line">{{ trip.summary }}</p>
                </div>
            </div>

            <div class="mb-4 flex flex-wrap items-center gap-2">
                <button type="button" class="btn-primary btn-sm" @click="openAdd()">Add stop</button>
                <button type="button" class="btn-outline btn-sm" @click="regenOpen = true">
                    <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" /> Regenerate suggestions
                </button>
                <button v-if="aiAvailable" type="button" class="btn-outline btn-sm" :disabled="summaryBusy || hasEmptyPlan" @click="writeSummary">
                    <Sparkles class="h-3.5 w-3.5" aria-hidden="true" /> {{ trip.summary ? 'Rewrite' : 'Write' }} AI overview
                </button>
                <span class="ml-auto text-sm text-muted-foreground">{{ totalStops }} {{ totalStops === 1 ? 'stop' : 'stops' }}</span>
            </div>

            <div v-if="hasEmptyPlan" class="panel mb-4 p-6 text-center">
                <h2 class="text-xl font-semibold">Your itinerary is empty</h2>
                <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    There were no stored places to suggest for these interests, or you removed them all. Regenerate suggestions or add your own stops.
                </p>
            </div>

            <div class="mb-3 flex gap-2 lg:hidden" role="tablist" aria-label="List or map">
                <button
                    type="button"
                    role="tab"
                    class="btn-outline flex-1"
                    :aria-selected="mobileView === 'list'"
                    :class="mobileView === 'list' ? '!bg-primary !text-primary-foreground' : ''"
                    @click="mobileView = 'list'"
                >
                    <List class="h-4 w-4" aria-hidden="true" /> Itinerary
                </button>
                <button
                    type="button"
                    role="tab"
                    class="btn-outline flex-1"
                    :aria-selected="mobileView === 'map'"
                    :class="mobileView === 'map' ? '!bg-primary !text-primary-foreground' : ''"
                    @click="mobileView = 'map'"
                >
                    <MapIcon class="h-4 w-4" aria-hidden="true" /> Map
                </button>
            </div>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div :class="mobileView === 'map' ? 'hidden lg:block' : ''">
                    <ItineraryBoard
                        :days="days"
                        :selected-stop-id="selectedStopId"
                        :active-day-id="activeDayId"
                        @select="selectStop"
                        @add="openAdd"
                        @select-day="(id) => (activeDayId = activeDayId === id ? null : id)"
                    />
                </div>

                <div :class="mobileView === 'list' ? 'hidden lg:block' : ''" class="lg:sticky lg:top-20 lg:self-start">
                    <div class="mb-2 flex flex-wrap items-center gap-1.5" role="group" aria-label="Map filters">
                        <button
                            type="button"
                            class="chip"
                            :class="!activeDayId ? 'chip-on' : ''"
                            :aria-pressed="!activeDayId"
                            @click="activeDayId = null"
                        >
                            All days
                        </button>
                        <button
                            v-for="d in days"
                            :key="d.id"
                            type="button"
                            class="chip"
                            :class="activeDayId === d.id ? 'chip-on' : ''"
                            :aria-pressed="activeDayId === d.id"
                            @click="activeDayId = activeDayId === d.id ? null : d.id"
                        >
                            Day {{ d.position + 1 }}
                        </button>
                    </div>
                    <div v-if="stopCategories.length > 1" class="mb-2 flex flex-wrap items-center gap-1.5" role="group" aria-label="Category filter">
                        <button type="button" class="chip" :class="!catFilter ? 'chip-on' : ''" :aria-pressed="!catFilter" @click="catFilter = ''">
                            All types
                        </button>
                        <button
                            v-for="c in stopCategories"
                            :key="c"
                            type="button"
                            class="chip"
                            :class="catFilter === c ? 'chip-on' : ''"
                            :aria-pressed="catFilter === c"
                            @click="catFilter = catFilter === c ? '' : c"
                        >
                            <span class="h-2 w-2 rounded-full" :style="{ background: categoryColor(c) }" aria-hidden="true" />{{ categoryLabel(c) }}
                        </button>
                    </div>
                    <div class="h-[26rem] sm:h-[32rem] lg:h-[calc(100vh-14rem)] lg:max-h-[40rem]">
                        <TripMap
                            ref="mapRef"
                            :points="points"
                            :selected-key="selectedStopId"
                            :route="routeLine"
                            :center="[trip.destination.latitude, trip.destination.longitude]"
                            :tile-url="map.tile_url"
                            :attribution="map.attribution"
                            :aria-label="`Map of stops in ${trip.destination.name}`"
                            empty-message="No stops with stored coordinates match this view."
                            @select="onMapSelect"
                        />
                    </div>
                    <p v-if="unmapped" class="hint">{{ unmapped }} stop(s) have no coordinates and appear only in the list.</p>

                    <div class="panel mt-3 p-3 text-sm">
                        <p class="font-medium">Routes</p>
                        <p class="hint">
                            Markers show stop locations, not a route. A line is drawn only when a routing service returns real route geometry.
                        </p>
                        <p v-if="!activeDayId" class="hint">Choose a single day above to look up its route.</p>
                        <div v-else class="mt-2 flex flex-wrap items-center gap-2">
                            <template v-if="routingEnabled">
                                <button type="button" class="btn-outline btn-sm" :disabled="routeLoading" @click="loadRoute('foot')">
                                    Walking route
                                </button>
                                <button type="button" class="btn-outline btn-sm" :disabled="routeLoading" @click="loadRoute('driving')">
                                    Driving route
                                </button>
                            </template>
                            <button v-else type="button" class="btn-outline btn-sm" :disabled="routeLoading" @click="loadRoute('foot')">
                                Get directions link
                            </button>
                        </div>
                        <p v-if="routeState?.status === 'ok'" class="mt-2" role="status">
                            {{ ((routeState.distance_m ?? 0) / 1000).toFixed(1) }} km · about {{ Math.round((routeState.duration_s ?? 0) / 60) }} min.
                            {{ routeState.attribution }}
                        </p>
                        <p v-else-if="routeState" class="mt-2 text-muted-foreground" role="status">{{ routeState.message }}</p>
                        <a
                            v-if="routeState?.directions_url"
                            :href="routeState.directions_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-1 inline-flex items-center gap-1 underline"
                            >Open directions in OpenStreetMap <ExternalLink class="h-3 w-3" aria-hidden="true"
                        /></a>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="tab === 'budget'" id="panel-budget" role="tabpanel" aria-labelledby="tab-budget" class="container-rw pb-12">
            <BudgetPanel :trip="trip" :budget="budget" :expenses="expenses" :categories="categories" :currencies="currencies" />
        </div>
        <div v-if="tab === 'weather'" id="panel-weather" role="tabpanel" aria-labelledby="tab-weather" class="container-rw pb-12">
            <WeatherPanel :trip-id="trip.id" :enabled="weatherEnabled" />
        </div>
        <div v-if="tab === 'details'" id="panel-details" role="tabpanel" aria-labelledby="tab-details" class="container-rw max-w-3xl pb-12">
            <DetailsForm
                :trip="trip"
                :interests="interestOptions"
                :paces="paces"
                :currencies="currencies"
                :max-days="maxDays"
                :has-expenses="expenses.length > 0"
            />
        </div>
        <div v-if="tab === 'share'" id="panel-share" role="tabpanel" aria-labelledby="tab-share" class="container-rw max-w-3xl pb-12">
            <SharePanel :trip-id="trip.id" :shares="shares" />
        </div>

        <AddStopDialog :open="addOpen" :trip-id="trip.id" :days="days" :places="places" :day-id="addDayId" @close="addOpen = false" />

        <ConfirmDialog
            :open="regenOpen"
            title="Regenerate suggestions"
            confirm-label="Regenerate"
            :busy="regenBusy"
            @cancel="regenOpen = false"
            @confirm="regenerate"
        >
            <fieldset class="space-y-3">
                <legend class="sr-only">Regeneration mode</legend>
                <label class="flex cursor-pointer gap-2"
                    ><input v-model="regenMode" type="radio" value="fill" class="mt-1 accent-[#216560]" /><span
                        ><strong class="text-foreground">Fill open slots only</strong><br />Adds new suggestions where your pace allows. Nothing is
                        removed.</span
                    ></label
                >
                <label class="flex cursor-pointer gap-2"
                    ><input v-model="regenMode" type="radio" value="replace" class="mt-1 accent-[#216560]" /><span
                        ><strong class="text-foreground">Replace untouched suggestions</strong><br />Removes {{ untouched }} suggested
                        {{ untouched === 1 ? 'stop' : 'stops' }} you haven't edited and plans them again with your current interests.
                        {{ protectedCount }} stop(s) you added or edited are kept.</span
                    ></label
                >
            </fieldset>
        </ConfirmDialog>

        <ConfirmDialog
            :open="deleteOpen"
            title="Delete this trip?"
            confirm-label="Delete trip"
            danger
            :busy="delForm.processing"
            @cancel="deleteOpen = false"
            @confirm="destroyTrip"
        >
            “{{ trip.title }}” and its itinerary, expenses and share links will be permanently removed.
        </ConfirmDialog>
    </RoamLayout>
</template>
