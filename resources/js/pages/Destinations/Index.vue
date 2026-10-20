<script setup lang="ts">
import DestinationArt from '@/components/rw/DestinationArt.vue';
import RoamLayout from '@/layouts/RoamLayout.vue';
import type { SharedData } from '@/types';
import type { DestinationLite, Option } from '@/types/roam';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bookmark, BookmarkCheck, Globe2, LoaderCircle, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    destinations: Paginated<DestinationLite>;
    filters: { q: string; interest: string };
    interests: Option[];
    lookupEnabled: boolean;
}>();

const user = computed(() => usePage<SharedData>().props.auth.user);
const q = ref(props.filters.q);
const interest = ref(props.filters.interest);

function apply(next: Partial<{ q: string; interest: string }> = {}) {
    const params: Record<string, string> = {};
    const nq = next.q ?? q.value;
    const ni = next.interest ?? interest.value;
    if (nq.trim()) params.q = nq.trim();
    if (ni) params.interest = ni;
    router.get(route('destinations.index'), params, { preserveState: true, preserveScroll: true });
}

function toggleInterest(key: string) {
    interest.value = interest.value === key ? '' : key;
    apply({ interest: interest.value });
}

function toggleSave(d: DestinationLite) {
    router.post(route('destinations.save', d.slug), {}, { preserveScroll: true, preserveState: true });
}

// Online lookup for places outside the curated catalog
interface Candidate {
    external_ref: string;
    name: string;
    region: string | null;
    country_name: string | null;
    country_code: string | null;
    latitude: number;
    longitude: number;
    timezone: string | null;
    signature: string;
}
const lookup = ref<{ loading: boolean; results: Candidate[]; error: string | null; done: boolean; attribution: string }>({
    loading: false,
    results: [],
    error: null,
    done: false,
    attribution: '',
});

async function runLookup() {
    lookup.value = { ...lookup.value, loading: true, error: null, done: false };
    try {
        const res = await fetch(route('destinations.lookup', { q: q.value.trim() }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (res.status === 429) throw new Error('Too many lookups. Wait a minute and try again.');
        if (!res.ok) throw new Error('Lookup failed.');
        const json = await res.json();
        lookup.value = { loading: false, results: json.results ?? [], error: json.error ?? null, done: true, attribution: json.attribution ?? '' };
    } catch (e) {
        lookup.value = { loading: false, results: [], error: e instanceof Error ? e.message : 'Lookup failed.', done: true, attribution: '' };
    }
}

function importCandidate(c: Candidate) {
    router.post(route('destinations.import'), { ...c });
}
</script>

<template>
    <Head title="Destinations" />
    <RoamLayout>
        <div class="container-rw py-10">
            <p class="eyebrow">Discover</p>
            <h1 class="mt-1 text-4xl font-semibold">Destinations</h1>
            <p class="mt-2 max-w-2xl text-muted-foreground">Curated cities with stored, coordinate-checked places. Filter by what you enjoy.</p>

            <form class="mt-6 flex max-w-xl gap-2" role="search" @submit.prevent="apply()">
                <label for="dq" class="sr-only">Search destinations</label>
                <input id="dq" v-model="q" type="search" maxlength="80" class="field" placeholder="Search by city or country" />
                <button type="submit" class="btn-primary"><Search class="h-4 w-4" aria-hidden="true" /> Search</button>
            </form>

            <fieldset class="mt-5">
                <legend class="sr-only">Filter by interest</legend>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="i in interests"
                        :key="i.key"
                        type="button"
                        class="chip px-3 py-1.5 text-sm"
                        :class="interest === i.key ? 'chip-on' : 'hover:bg-sage-100'"
                        :aria-pressed="interest === i.key"
                        @click="toggleInterest(i.key)"
                    >
                        {{ i.label }}
                    </button>
                </div>
            </fieldset>

            <p class="mt-6 text-sm text-muted-foreground" role="status">
                {{ destinations.total }} {{ destinations.total === 1 ? 'destination' : 'destinations' }} found
            </p>

            <div v-if="!destinations.data.length" class="panel mt-4 p-8 text-center">
                <Globe2 class="mx-auto h-8 w-8 text-muted-foreground" aria-hidden="true" />
                <h2 class="mt-3 text-xl font-semibold">No curated destinations match</h2>
                <p class="mt-1 text-sm text-muted-foreground">Try a different search or clear the interest filter.</p>
                <button
                    v-if="filters.q || filters.interest"
                    type="button"
                    class="btn-outline mt-4"
                    @click="((q = ''), (interest = ''), apply({ q: '', interest: '' }))"
                >
                    Clear filters
                </button>
            </div>

            <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="d in destinations.data" :key="d.id" class="panel relative overflow-hidden">
                    <Link :href="route('destinations.show', d.slug)" class="block">
                        <div class="h-36"><DestinationArt :name="d.name" /></div>
                        <div class="p-4">
                            <h2 class="text-lg font-semibold">
                                {{ d.name
                                }}<span v-if="d.country_name" class="font-sans text-sm font-normal text-muted-foreground">
                                    · {{ d.country_name }}</span
                                >
                            </h2>
                            <p class="mt-1 line-clamp-2 text-sm text-muted-foreground">{{ d.summary }}</p>
                            <p class="mt-2 text-xs font-medium text-brand-600 dark:text-brand-100">{{ d.places_count }} stored places</p>
                        </div>
                    </Link>
                    <button
                        v-if="user"
                        type="button"
                        class="absolute right-3 top-3 rounded-full bg-card/90 p-2 shadow"
                        :aria-label="d.saved ? `Remove ${d.name} from saved` : `Save ${d.name}`"
                        :aria-pressed="d.saved"
                        @click="toggleSave(d)"
                    >
                        <BookmarkCheck v-if="d.saved" class="h-4 w-4 text-terra-500" /><Bookmark v-else class="h-4 w-4" />
                    </button>
                </li>
            </ul>

            <nav v-if="destinations.last_page > 1" class="mt-8 flex flex-wrap items-center justify-center gap-1" aria-label="Pagination">
                <template v-for="l in destinations.links" :key="l.label">
                    <Link
                        v-if="l.url"
                        :href="l.url"
                        class="btn-outline btn-sm"
                        :class="l.active ? '!bg-primary !text-primary-foreground' : ''"
                        :aria-current="l.active ? 'page' : undefined"
                        preserve-scroll
                        ><span v-html="l.label"
                    /></Link>
                    <span v-else class="btn-outline btn-sm opacity-40" aria-hidden="true"><span v-html="l.label" /></span>
                </template>
            </nav>

            <section v-if="lookupEnabled" class="panel mt-12 p-6" aria-labelledby="lookup-h">
                <h2 id="lookup-h" class="text-xl font-semibold">Can't find your destination?</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Look up any city online. Online destinations have a map and weather but <strong>no stored places</strong>, so you add your own
                    stops. Uses Open-Meteo's geocoding service.
                </p>
                <template v-if="user">
                    <button type="button" class="btn-outline mt-4" :disabled="q.trim().length < 2 || lookup.loading" @click="runLookup">
                        <LoaderCircle v-if="lookup.loading" class="h-4 w-4 animate-spin" aria-hidden="true" /> Look up “{{ q.trim() || '…' }}” online
                    </button>
                    <p v-if="q.trim().length < 2" class="hint">Type at least 2 characters in the search box above.</p>
                    <p v-if="lookup.error" class="err" role="alert">{{ lookup.error }}</p>
                    <p v-else-if="lookup.done && !lookup.results.length" class="mt-3 text-sm" role="status">No online matches.</p>
                    <ul v-if="lookup.results.length" class="mt-4 divide-y divide-border">
                        <li v-for="c in lookup.results" :key="c.external_ref" class="flex flex-wrap items-center justify-between gap-2 py-3">
                            <div>
                                <p class="font-semibold">{{ c.name }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ [c.region, c.country_name].filter(Boolean).join(', ') }} · {{ c.latitude.toFixed(3) }},
                                    {{ c.longitude.toFixed(3) }}
                                </p>
                            </div>
                            <button type="button" class="btn-primary btn-sm" @click="importCandidate(c)">Plan a trip here</button>
                        </li>
                    </ul>
                    <p v-if="lookup.attribution" class="hint">{{ lookup.attribution }}</p>
                </template>
                <p v-else class="mt-4 text-sm">
                    <Link :href="route('login')" class="font-semibold underline">Log in</Link> to look up other destinations.
                </p>
            </section>
        </div>
    </RoamLayout>
</template>
