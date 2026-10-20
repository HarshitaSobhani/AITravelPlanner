<script setup lang="ts">
import { categoryLabel } from '@/lib/categories';
import { formatDate } from '@/lib/format';
import type { Day, Place } from '@/types/roam';
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{ open: boolean; tripId: number; days: Day[]; places: Place[]; dayId: number | null }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const dialog = ref<HTMLDialogElement | null>(null);
const mode = ref<'place' | 'custom'>('place');
const search = ref('');

const form = useForm({
    itinerary_day_id: null as number | null,
    point_of_interest_id: null as number | null,
    name: '',
    latitude: '' as string | number,
    longitude: '' as string | number,
    duration_minutes: '' as string | number,
    start_time: '',
    notes: '',
});

const used = computed(() => new Set(props.days.flatMap((d) => d.stops.map((s) => s.place_id).filter(Boolean))));
const available = computed(() =>
    props.places.filter((p) => !used.value.has(p.id) && `${p.name} ${p.category}`.toLowerCase().includes(search.value.trim().toLowerCase())),
);

watch(
    () => props.open,
    async (o) => {
        await nextTick();
        const el = dialog.value;
        if (!el) return;
        if (o) {
            form.clearErrors();
            form.point_of_interest_id = null;
            form.name = '';
            form.latitude = '';
            form.longitude = '';
            form.duration_minutes = '';
            form.start_time = '';
            form.notes = '';
            form.itinerary_day_id = props.dayId ?? props.days[0]?.id ?? null;
            mode.value = 'place';
            search.value = '';
            if (!el.open) el.showModal();
        } else if (el.open) el.close();
    },
    { immediate: true },
);

function submit() {
    form.transform((d) => {
        const base: Record<string, unknown> = {
            itinerary_day_id: d.itinerary_day_id,
            duration_minutes: d.duration_minutes === '' ? null : Number(d.duration_minutes),
            start_time: d.start_time || null,
            notes: d.notes || null,
        };
        if (mode.value === 'place') base.point_of_interest_id = d.point_of_interest_id;
        else {
            base.name = d.name;
            base.latitude = d.latitude === '' ? null : Number(d.latitude);
            base.longitude = d.longitude === '' ? null : Number(d.longitude);
        }
        return base;
    }).post(route('stops.store', props.tripId), { preserveScroll: true, preserveState: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <dialog
        ref="dialog"
        class="w-[min(34rem,calc(100vw-1.5rem))] rounded-2xl border border-border bg-card p-0 text-foreground shadow-xl backdrop:bg-black/40"
        aria-labelledby="add-stop-h"
        @cancel.prevent="emit('close')"
    >
        <form class="p-5 sm:p-6" @submit.prevent="submit">
            <h2 id="add-stop-h" class="text-xl font-semibold">Add a stop</h2>
            <div class="mt-4">
                <label for="as-day" class="label">Day</label>
                <select id="as-day" v-model="form.itinerary_day_id" class="field" required>
                    <option v-for="d in days" :key="d.id" :value="d.id">
                        Day {{ d.position + 1 }} · {{ formatDate(d.date, { weekday: 'short', day: 'numeric', month: 'short' }) }}
                    </option>
                </select>
                <p class="err">{{ form.errors.itinerary_day_id }}</p>
            </div>

            <div class="mt-4 flex gap-2" role="tablist" aria-label="Stop type">
                <button
                    type="button"
                    role="tab"
                    class="btn-outline btn-sm"
                    :aria-selected="mode === 'place'"
                    :class="mode === 'place' ? '!bg-primary !text-primary-foreground' : ''"
                    @click="mode = 'place'"
                >
                    Stored place
                </button>
                <button
                    type="button"
                    role="tab"
                    class="btn-outline btn-sm"
                    :aria-selected="mode === 'custom'"
                    :class="mode === 'custom' ? '!bg-primary !text-primary-foreground' : ''"
                    @click="mode = 'custom'"
                >
                    My own stop
                </button>
            </div>

            <div v-if="mode === 'place'" class="mt-4">
                <label for="as-search" class="label">Find a place</label>
                <input id="as-search" v-model="search" type="search" class="field" placeholder="Filter by name or category" />
                <p v-if="!places.length" class="hint">This destination has no stored places. Use “My own stop”.</p>
                <p v-else-if="!available.length" class="hint">No unused places match.</p>
                <ul class="mt-2 max-h-52 overflow-y-auto rounded-xl border border-border" role="radiogroup" aria-label="Places">
                    <li v-for="p in available" :key="p.id">
                        <label
                            class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-muted has-[:checked]:bg-brand-50 dark:has-[:checked]:bg-brand-700/20"
                        >
                            <input v-model="form.point_of_interest_id" type="radio" name="poi" :value="p.id" class="accent-[#216560]" />
                            <span
                                ><span class="font-medium">{{ p.name }}</span>
                                <span class="text-xs text-muted-foreground">· {{ categoryLabel(p.category) }}</span></span
                            >
                        </label>
                    </li>
                </ul>
                <p class="err">{{ form.errors.point_of_interest_id }}</p>
            </div>

            <div v-else class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="as-name" class="label">Name</label>
                    <input id="as-name" v-model="form.name" class="field" maxlength="160" />
                    <p class="err">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label for="as-lat" class="label">Latitude <span class="font-normal text-muted-foreground">(optional)</span></label>
                    <input id="as-lat" v-model="form.latitude" type="number" step="any" min="-90" max="90" class="field" />
                    <p class="err">{{ form.errors.latitude }}</p>
                </div>
                <div>
                    <label for="as-lon" class="label">Longitude <span class="font-normal text-muted-foreground">(optional)</span></label>
                    <input id="as-lon" v-model="form.longitude" type="number" step="any" min="-180" max="180" class="field" />
                    <p class="err">{{ form.errors.longitude }}</p>
                </div>
                <p class="hint sm:col-span-2">Without coordinates the stop appears in the list but not on the map.</p>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="as-dur" class="label">Visit length (min) <span class="font-normal text-muted-foreground">(optional)</span></label>
                    <input id="as-dur" v-model="form.duration_minutes" type="number" min="5" max="720" step="5" class="field" />
                    <p class="err">{{ form.errors.duration_minutes }}</p>
                </div>
                <div>
                    <label for="as-time" class="label">Start time <span class="font-normal text-muted-foreground">(optional)</span></label>
                    <input id="as-time" v-model="form.start_time" type="time" class="field" />
                </div>
                <div class="sm:col-span-2">
                    <label for="as-notes" class="label">Notes</label>
                    <textarea id="as-notes" v-model="form.notes" rows="2" maxlength="1000" class="field" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="btn-outline" @click="emit('close')">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="form.processing || (mode === 'place' ? !form.point_of_interest_id : !form.name)">
                    Add stop
                </button>
            </div>
        </form>
    </dialog>
</template>
