<script setup lang="ts">
import DestinationArt from '@/components/rw/DestinationArt.vue';
import RoamLayout from '@/layouts/RoamLayout.vue';
import type { DestinationLite, Option } from '@/types/roam';
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarDays, Compass, Map, Search, Wallet } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    destinations: DestinationLite[];
    interests: Option[];
    examples: { destination: string; slug: string; interests: string[]; days: { name: string; start_time: string }[][] }[];
}>();

const q = ref('');
function search() {
    router.get(route('destinations.index'), q.value.trim() ? { q: q.value.trim() } : {});
}

const steps = [
    { icon: Search, title: 'Choose a destination', text: 'Browse the curated catalog or look up another city.' },
    { icon: CalendarDays, title: 'Set dates and pace', text: 'Dates, travellers, budget, interests and how packed each day should be.' },
    { icon: Map, title: 'Edit your plan', text: 'A day-by-day suggestion on a real map. Add, remove, reorder and move stops.' },
    { icon: Wallet, title: 'Track the budget', text: 'Enter your own estimates and actuals. Share or print when ready.' },
];
</script>

<template>
    <Head title="Plan trips around what you love" />
    <RoamLayout>
        <section class="border-b border-border bg-sand-100 dark:bg-card">
            <div class="container-rw grid items-center gap-10 py-14 md:grid-cols-2 md:py-20">
                <div>
                    <p class="eyebrow">Trip planning, not guesswork</p>
                    <h1 class="mt-3 text-4xl font-semibold leading-tight sm:text-5xl">Plan the trip that fits your interests, pace and budget.</h1>
                    <p class="mt-4 max-w-xl text-lg text-ink-soft dark:text-muted-foreground">
                        RoamWise turns your dates and interests into an editable day-by-day itinerary on a real map, with a budget you control. No
                        account needed to explore destinations.
                    </p>
                    <form class="mt-8 flex max-w-lg flex-col gap-2 sm:flex-row" role="search" @submit.prevent="search">
                        <label for="home-search" class="sr-only">Search destinations</label>
                        <input
                            id="home-search"
                            v-model="q"
                            type="search"
                            maxlength="80"
                            placeholder="Try Kyoto, Lisbon, Rome…"
                            class="field h-12 flex-1"
                            autocomplete="off"
                        />
                        <button type="submit" class="btn-primary h-12"><Search class="h-4 w-4" aria-hidden="true" /> Search</button>
                    </form>
                    <div class="mt-5 flex flex-wrap items-center gap-3 text-sm">
                        <Link :href="route('trips.create')" class="btn-terra"><Compass class="h-4 w-4" aria-hidden="true" /> Plan a trip</Link>
                        <Link
                            :href="route('destinations.index')"
                            class="font-semibold text-brand-600 underline-offset-4 hover:underline dark:text-brand-100"
                            >Browse destinations</Link
                        >
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3" aria-hidden="true">
                    <div
                        v-for="(d, i) in destinations.slice(0, 4)"
                        :key="d.slug"
                        class="overflow-hidden rounded-2xl"
                        :class="i % 2 ? 'mt-8 h-44' : 'h-52'"
                    >
                        <DestinationArt :name="d.name" />
                    </div>
                </div>
            </div>
        </section>

        <section class="container-rw py-14" aria-labelledby="how">
            <h2 id="how" class="text-3xl font-semibold">How it works</h2>
            <ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <li v-for="(s, i) in steps" :key="s.title" class="panel p-5">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-50 text-brand-700 dark:bg-brand-700/30 dark:text-brand-100"
                            ><component :is="s.icon" class="h-5 w-5" aria-hidden="true"
                        /></span>
                        <span class="text-xs font-semibold text-muted-foreground">Step {{ i + 1 }}</span>
                    </div>
                    <h3 class="mt-3 text-lg font-semibold">{{ s.title }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">{{ s.text }}</p>
                </li>
            </ol>
        </section>

        <section class="container-rw pb-4" aria-labelledby="discover">
            <div class="flex items-end justify-between gap-4">
                <h2 id="discover" class="text-3xl font-semibold">Discover destinations</h2>
                <Link :href="route('destinations.index')" class="text-sm font-semibold text-brand-600 hover:underline dark:text-brand-100"
                    >See all</Link
                >
            </div>
            <p v-if="!destinations.length" class="panel mt-6 p-6 text-sm text-muted-foreground">
                No destinations are loaded yet. Run the database seeder to load the curated catalog.
            </p>
            <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="d in destinations" :key="d.slug">
                    <Link :href="route('destinations.show', d.slug)" class="panel group block overflow-hidden transition hover:shadow-md">
                        <div class="h-36"><DestinationArt :name="d.name" /></div>
                        <div class="p-4">
                            <h3 class="text-lg font-semibold">
                                {{ d.name
                                }}<span v-if="d.country_name" class="font-sans text-sm font-normal text-muted-foreground">
                                    · {{ d.country_name }}</span
                                >
                            </h3>
                            <p class="mt-1 line-clamp-2 text-sm text-muted-foreground">{{ d.summary }}</p>
                            <p class="mt-2 text-xs font-medium text-brand-600 dark:text-brand-100">{{ d.places_count }} stored places</p>
                        </div>
                    </Link>
                </li>
            </ul>
        </section>

        <section class="container-rw py-14" aria-labelledby="interests">
            <h2 id="interests" class="text-3xl font-semibold">Start from what you love</h2>
            <p class="mt-2 text-muted-foreground">Pick an interest to see destinations with matching places.</p>
            <ul class="mt-6 flex flex-wrap gap-2">
                <li v-for="i in interests" :key="i.key">
                    <Link
                        :href="route('destinations.index', { interest: i.key })"
                        class="chip px-4 py-2 text-sm hover:bg-sage-100 dark:hover:bg-sage-700/30"
                        >{{ i.label }}</Link
                    >
                </li>
            </ul>
        </section>

        <section v-if="examples.length" class="border-y border-border bg-sand-100 py-14 dark:bg-card" aria-labelledby="examples">
            <div class="container-rw">
                <p class="eyebrow">Examples</p>
                <h2 id="examples" class="mt-1 text-3xl font-semibold">What a suggested plan looks like</h2>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                    These are <strong>examples</strong> produced by RoamWise's planner from stored places for sample interests. They contain no
                    prices, availability or opening hours, and are not quotes or bookings. Your plan will use your own dates and preferences.
                </p>
                <div class="mt-8 grid gap-4 lg:grid-cols-3">
                    <article v-for="e in examples" :key="e.slug" class="panel p-5">
                        <span class="chip">Example itinerary</span>
                        <h3 class="mt-3 text-xl font-semibold">2 days in {{ e.destination }}</h3>
                        <p class="text-xs text-muted-foreground">Interests: {{ e.interests.join(', ') }} · balanced pace</p>
                        <div v-for="(day, di) in e.days" :key="di" class="mt-4">
                            <h4 class="text-sm font-semibold">Day {{ di + 1 }}</h4>
                            <ul class="mt-1 space-y-1 text-sm">
                                <li v-for="s in day" :key="s.name" class="flex gap-3">
                                    <span class="w-12 shrink-0 font-semibold tabular-nums text-brand-600 dark:text-brand-100">{{ s.start_time }}</span
                                    ><span>{{ s.name }}</span>
                                </li>
                            </ul>
                        </div>
                        <Link :href="route('trips.create', { destination: e.slug })" class="btn-outline btn-sm mt-5"
                            >Plan my own {{ e.destination }} trip</Link
                        >
                    </article>
                </div>
            </div>
        </section>

        <section class="container-rw py-14 text-center">
            <h2 class="text-3xl font-semibold">Ready to plan?</h2>
            <p class="mt-2 text-muted-foreground">Create a free account to save trips, edit them any time and share a read-only link.</p>
            <div class="mt-6 flex justify-center gap-3">
                <Link :href="route('register')" class="btn-primary">Create an account</Link>
                <Link :href="route('destinations.index')" class="btn-outline">Explore first</Link>
            </div>
        </section>
    </RoamLayout>
</template>
