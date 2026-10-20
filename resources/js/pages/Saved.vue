<script setup lang="ts">
import RoamLayout from '@/layouts/RoamLayout.vue';
import { categoryLabel } from '@/lib/categories';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    destinations: { id: number; slug: string; name: string; country_name: string | null }[];
    places: { id: number; name: string; category: string; destination: { name: string; slug: string } }[];
}>();
</script>

<template>
    <Head title="Saved" />
    <RoamLayout>
        <div class="container-rw py-10">
            <h1 class="text-4xl font-semibold">Saved</h1>
            <section class="mt-8" aria-labelledby="sd">
                <h2 id="sd" class="text-xl font-semibold">Destinations</h2>
                <p v-if="!destinations.length" class="panel mt-3 p-5 text-sm text-muted-foreground">
                    Nothing saved yet. Use the bookmark on any <Link :href="route('destinations.index')" class="underline">destination</Link>.
                </p>
                <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="d in destinations" :key="d.id" class="panel p-4">
                        <Link :href="route('destinations.show', d.slug)" class="font-semibold hover:underline">{{ d.name }}</Link>
                        <p class="text-xs text-muted-foreground">{{ d.country_name }}</p>
                        <Link :href="route('trips.create', { destination: d.slug })" class="btn-outline btn-sm mt-3">Plan a trip</Link>
                    </li>
                </ul>
            </section>
            <section class="mt-10" aria-labelledby="sp">
                <h2 id="sp" class="text-xl font-semibold">Places</h2>
                <p v-if="!places.length" class="panel mt-3 p-5 text-sm text-muted-foreground">
                    No saved places yet. Save places from a destination page.
                </p>
                <ul class="mt-3 divide-y divide-border rounded-2xl border border-border bg-card">
                    <li v-for="p in places" :key="p.id" class="flex items-center justify-between gap-3 p-4">
                        <div>
                            <p class="font-medium">{{ p.name }}</p>
                            <p class="text-xs text-muted-foreground">{{ categoryLabel(p.category) }} · {{ p.destination.name }}</p>
                        </div>
                        <Link :href="route('destinations.show', p.destination.slug)" class="btn-ghost btn-sm">View</Link>
                    </li>
                </ul>
            </section>
        </div>
    </RoamLayout>
</template>
