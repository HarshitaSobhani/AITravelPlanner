<script setup lang="ts">
import TripMap, { type MapPoint } from '@/components/rw/TripMap.vue';
import RoamLayout from '@/layouts/RoamLayout.vue';
import { categoryColor, categoryLabel } from '@/lib/categories';
import { formatDuration, isSafeUrl } from '@/lib/format';
import type { SharedData } from '@/types';
import type { DestinationLite, MapConfig, Option, Place } from '@/types/roam';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bookmark, BookmarkCheck, ExternalLink, List, Map as MapIcon, MapPin } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    destination: {
        id: number;
        slug: string;
        name: string;
        country_name: string | null;
        latitude: number;
        longitude: number;
        summary: string | null;
        best_season: string | null;
        saved: boolean;
        source: { name: string; url: string | null; license: string | null; attribution: string | null } | null;
    };
    places: Place[];
    savedPlaceIds: number[];
    categories: string[];
    interests: Option[];
    related: DestinationLite[];
    map: MapConfig;
}>();

const user = computed(() => usePage<SharedData>().props.auth.user);
const category = ref<string>('');
const interest = ref<string>('');
const selected = ref<number | null>(null);
const view = ref<'list' | 'map'>('list');
const mapRef = ref<InstanceType<typeof TripMap> | null>(null);

const filtered = computed(() =>
    props.places.filter((p) => (!category.value || p.category === category.value) && (!interest.value || p.interests.includes(interest.value))),
);
const points = computed<MapPoint[]>(() =>
    filtered.value.map((p) => ({
        key: p.id,
        name: p.name,
        latitude: p.latitude,
        longitude: p.longitude,
        category: p.category,
        detail: p.neighbourhood,
    })),
);

watch(view, async (v) => {
    if (v === 'map') {
        await new Promise((r) => setTimeout(r, 50));
        mapRef.value?.invalidate();
    }
});
watch(filtered, (list) => {
    if (selected.value !== null && !list.some((p) => p.id === selected.value)) selected.value = null;
});

function select(id: number | string) {
    selected.value = Number(id);
    document.getElementById(`place-${id}`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function togglePlace(p: Place) {
    router.post(route('places.save', p.id), {}, { preserveScroll: true, preserveState: true });
}
function toggleDestination() {
    router.post(route('destinations.save', props.destination.slug), {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head :title="destination.name" />
    <RoamLayout>
        <div class="container-rw py-8">
            <nav class="text-sm text-muted-foreground" aria-label="Breadcrumb">
                <Link :href="route('destinations.index')" class="hover:underline">Destinations</Link> /
                <span aria-current="page">{{ destination.name }}</span>
            </nav>
            <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-4xl font-semibold">
                        {{ destination.name
                        }}<span v-if="destination.country_name" class="font-sans text-xl font-normal text-muted-foreground">
                            · {{ destination.country_name }}</span
                        >
                    </h1>
                    <p v-if="destination.summary" class="mt-2 max-w-2xl text-muted-foreground">{{ destination.summary }}</p>
                    <p v-if="destination.best_season" class="mt-1 text-sm"><strong>Seasons:</strong> {{ destination.best_season }}</p>
                </div>
                <div class="flex gap-2">
                    <button v-if="user" type="button" class="btn-outline" :aria-pressed="destination.saved" @click="toggleDestination">
                        <BookmarkCheck v-if="destination.saved" class="h-4 w-4 text-terra-500" aria-hidden="true" /><Bookmark
                            v-else
                            class="h-4 w-4"
                            aria-hidden="true"
                        />
                        {{ destination.saved ? 'Saved' : 'Save' }}
                    </button>
                    <Link :href="route('trips.create', { destination: destination.slug })" class="btn-terra">Plan a trip here</Link>
                </div>
            </div>

            <div class="mt-8 flex flex-wrap items-center gap-2" role="group" aria-label="Filter places">
                <span class="mr-1 text-sm font-medium">Category</span>
                <button type="button" class="chip" :class="!category ? 'chip-on' : ''" :aria-pressed="!category" @click="category = ''">All</button>
                <button
                    v-for="c in categories"
                    :key="c"
                    type="button"
                    class="chip"
                    :class="category === c ? 'chip-on' : ''"
                    :aria-pressed="category === c"
                    @click="category = category === c ? '' : c"
                >
                    <span class="h-2 w-2 rounded-full" :style="{ background: categoryColor(c) }" aria-hidden="true" />{{ categoryLabel(c) }}
                </button>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2" role="group" aria-label="Filter by interest">
                <span class="mr-1 text-sm font-medium">Interest</span>
                <button type="button" class="chip" :class="!interest ? 'chip-on' : ''" :aria-pressed="!interest" @click="interest = ''">Any</button>
                <button
                    v-for="i in interests"
                    :key="i.key"
                    type="button"
                    class="chip"
                    :class="interest === i.key ? 'chip-on' : ''"
                    :aria-pressed="interest === i.key"
                    @click="interest = interest === i.key ? '' : i.key"
                >
                    {{ i.label }}
                </button>
            </div>

            <div class="mt-4 flex gap-2 lg:hidden" role="tablist" aria-label="Map or list">
                <button
                    type="button"
                    role="tab"
                    class="btn-outline flex-1"
                    :aria-selected="view === 'list'"
                    :class="view === 'list' ? '!bg-primary !text-primary-foreground' : ''"
                    @click="view = 'list'"
                >
                    <List class="h-4 w-4" aria-hidden="true" /> List
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

            <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,26rem)_1fr]">
                <section :class="view === 'map' ? 'hidden lg:block' : ''" aria-label="Places" class="lg:max-h-[36rem] lg:overflow-y-auto lg:pr-1">
                    <p class="mb-2 text-sm text-muted-foreground" role="status">{{ filtered.length }} of {{ places.length }} places</p>
                    <div v-if="!places.length" class="panel p-5 text-sm">
                        <p class="font-medium">No stored places for {{ destination.name }}.</p>
                        <p class="mt-1 text-muted-foreground">You can still create a trip here and add your own stops.</p>
                    </div>
                    <div v-else-if="!filtered.length" class="panel p-5 text-sm">
                        No places match these filters.
                        <button type="button" class="underline" @click="((category = ''), (interest = ''))">Clear filters</button>
                    </div>
                    <ul class="space-y-2">
                        <li
                            v-for="p in filtered"
                            :id="`place-${p.id}`"
                            :key="p.id"
                            class="panel p-4"
                            :class="selected === p.id ? 'ring-2 ring-terra-500' : ''"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <button
                                    type="button"
                                    class="text-left"
                                    :aria-label="`Show ${p.name} on the map`"
                                    @click="((selected = p.id), (view = 'map'))"
                                >
                                    <span class="flex items-center gap-2 font-semibold"
                                        ><span
                                            class="h-2.5 w-2.5 shrink-0 rounded-full"
                                            :style="{ background: categoryColor(p.category) }"
                                            aria-hidden="true"
                                        />{{ p.name }}</span
                                    >
                                    <span class="text-xs text-muted-foreground"
                                        >{{ categoryLabel(p.category) }}<template v-if="p.neighbourhood"> · {{ p.neighbourhood }}</template> · ~{{
                                            formatDuration(p.duration)
                                        }}</span
                                    >
                                </button>
                                <button
                                    v-if="user"
                                    type="button"
                                    class="rounded p-1.5 hover:bg-muted"
                                    :aria-label="savedPlaceIds.includes(p.id) ? `Remove ${p.name} from saved places` : `Save ${p.name}`"
                                    :aria-pressed="savedPlaceIds.includes(p.id)"
                                    @click="togglePlace(p)"
                                >
                                    <BookmarkCheck v-if="savedPlaceIds.includes(p.id)" class="h-4 w-4 text-terra-500" /><Bookmark
                                        v-else
                                        class="h-4 w-4"
                                    />
                                </button>
                            </div>
                            <p v-if="p.description" class="mt-2 text-sm text-muted-foreground">{{ p.description }}</p>
                            <p class="mt-2 flex flex-wrap items-center gap-x-3 text-xs text-muted-foreground">
                                <span
                                    :title="p.verified ? 'Coordinates cross-checked against OpenStreetMap' : 'Coordinates curated; not cross-checked'"
                                    >{{ p.verified ? 'Coordinates cross-checked' : 'Coordinates approximate' }}</span
                                >
                                <span>Opening hours: not stored</span>
                                <a
                                    v-if="isSafeUrl(p.website)"
                                    :href="p.website"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 underline"
                                    >Website <ExternalLink class="h-3 w-3" aria-hidden="true"
                                /></a>
                            </p>
                        </li>
                    </ul>
                </section>

                <section :class="view === 'list' ? 'hidden lg:block' : ''" aria-label="Map" class="h-[26rem] lg:h-[36rem]">
                    <TripMap
                        ref="mapRef"
                        :points="points"
                        :selected-key="selected"
                        :center="[destination.latitude, destination.longitude]"
                        :tile-url="map.tile_url"
                        :attribution="map.attribution"
                        :aria-label="`Map of places in ${destination.name}`"
                        empty-message="No stored places to show on the map."
                        @select="select"
                    />
                </section>
            </div>

            <section v-if="related.length" class="mt-14" aria-labelledby="rel">
                <h2 id="rel" class="text-2xl font-semibold">Related destinations</h2>
                <ul class="mt-4 grid gap-3 sm:grid-cols-3">
                    <li v-for="r in related" :key="r.id" class="panel p-4">
                        <Link :href="route('destinations.show', r.slug)" class="font-semibold hover:underline"
                            ><MapPin class="mr-1 inline h-4 w-4 text-terra-500" aria-hidden="true" />{{ r.name }}</Link
                        >
                        <p class="text-xs text-muted-foreground">{{ r.country_name }}</p>
                    </li>
                </ul>
            </section>

            <p class="mt-10 text-xs text-muted-foreground">
                Source: {{ destination.source?.name ?? 'RoamWise'
                }}<template v-if="destination.source?.attribution"> — {{ destination.source.attribution }}</template
                >. No live opening hours, prices or availability are shown.
            </p>
        </div>
    </RoamLayout>
</template>
