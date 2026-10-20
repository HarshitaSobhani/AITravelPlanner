<script setup lang="ts">
import ConfirmDialog from '@/components/rw/ConfirmDialog.vue';
import RoamLayout from '@/layouts/RoamLayout.vue';
import { formatMoney, formatRange, statusLabels, timeAgo } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/vue3';
import { MapPinned, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface TripRow {
    id: number;
    title: string;
    destination: string;
    start_date: string;
    end_date: string;
    status: 'upcoming' | 'in_progress' | 'completed';
    stops_count: number;
    travelers: number;
    currency: string;
    exponent: number;
    budget_minor: number | null;
    updated_at: string;
}

defineProps<{ trips: TripRow[] }>();

const toDelete = ref<TripRow | null>(null);
const busy = ref(false);

function destroy() {
    if (!toDelete.value) return;
    busy.value = true;
    router.delete(route('trips.destroy', toDelete.value.id), {
        onFinish: () => {
            busy.value = false;
            toDelete.value = null;
        },
    });
}

const statusClass: Record<string, string> = {
    upcoming: 'bg-brand-50 text-brand-700 dark:bg-brand-700/30 dark:text-brand-100',
    in_progress: 'bg-terra-100 text-terra-600 dark:bg-terra-600/20 dark:text-terra-100',
    completed: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head title="My trips" />
    <RoamLayout>
        <div class="container-rw py-10">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="eyebrow">Dashboard</p>
                    <h1 class="mt-1 text-4xl font-semibold">My trips</h1>
                </div>
                <Link :href="route('trips.create')" class="btn-terra"><Plus class="h-4 w-4" aria-hidden="true" /> New trip</Link>
            </div>

            <div v-if="!trips.length" class="panel mt-8 p-10 text-center">
                <MapPinned class="mx-auto h-10 w-10 text-brand-500" aria-hidden="true" />
                <h2 class="mt-4 text-2xl font-semibold">No trips yet</h2>
                <p class="mx-auto mt-2 max-w-md text-muted-foreground">
                    Pick a destination, dates and interests. RoamWise suggests a day-by-day plan you can edit.
                </p>
                <div class="mt-6 flex justify-center gap-3">
                    <Link :href="route('trips.create')" class="btn-primary">Plan your first trip</Link>
                    <Link :href="route('destinations.index')" class="btn-outline">Browse destinations</Link>
                </div>
            </div>

            <ul v-else class="mt-8 grid gap-4 md:grid-cols-2">
                <li v-for="t in trips" :key="t.id" class="panel flex flex-col p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate text-xl font-semibold">
                                <Link :href="route('trips.show', t.id)" class="hover:underline">{{ t.title }}</Link>
                            </h2>
                            <p class="text-sm text-muted-foreground">{{ t.destination }}</p>
                        </div>
                        <span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold" :class="statusClass[t.status]">{{
                            statusLabels[t.status]
                        }}</span>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">Dates</dt>
                            <dd class="font-medium">{{ formatRange(t.start_date, t.end_date) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Planned stops</dt>
                            <dd class="font-medium">{{ t.stops_count }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Your budget</dt>
                            <dd class="font-medium">
                                {{ t.budget_minor === null ? 'Not set' : formatMoney(t.budget_minor, t.currency, t.exponent) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Last updated</dt>
                            <dd class="font-medium">{{ timeAgo(t.updated_at) }}</dd>
                        </div>
                    </dl>
                    <div class="mt-5 flex flex-wrap gap-2 pt-1">
                        <Link :href="route('trips.show', t.id)" class="btn-primary btn-sm">Open &amp; edit</Link>
                        <a :href="route('trips.export', t.id)" class="btn-outline btn-sm">Print / PDF</a>
                        <button type="button" class="btn-danger btn-sm ml-auto" :aria-label="`Delete ${t.title}`" @click="toDelete = t">
                            <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /> Delete
                        </button>
                    </div>
                </li>
            </ul>
        </div>

        <ConfirmDialog
            :open="!!toDelete"
            title="Delete this trip?"
            confirm-label="Delete trip"
            danger
            :busy="busy"
            @cancel="toDelete = null"
            @confirm="destroy"
        >
            “{{ toDelete?.title }}” and its itinerary, expenses and share links will be permanently removed. This cannot be undone.
        </ConfirmDialog>
    </RoamLayout>
</template>
