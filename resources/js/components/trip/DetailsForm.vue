<script setup lang="ts">
import { dayCount } from '@/lib/format';
import type { Option, Pace, TripData } from '@/types/roam';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ trip: TripData; interests: Option[]; paces: Pace[]; currencies: string[]; maxDays: number; hasExpenses: boolean }>();

const p = props.trip.preferences!;
const form = useForm({
    title: props.trip.title,
    start_date: props.trip.start_date,
    end_date: props.trip.end_date,
    travelers: props.trip.travelers as number | string,
    currency: props.trip.currency,
    budget: props.trip.budget_input ?? '',
    pace: props.trip.pace,
    interests: [...p.interests],
    accessibility: p.accessibility ?? '',
    dietary: p.dietary ?? '',
    accommodation: p.accommodation ?? '',
    notes: props.trip.notes ?? '',
});

const newDays = computed(() =>
    form.start_date && form.end_date && form.end_date >= form.start_date ? dayCount(form.start_date, form.end_date) : null,
);
const shrinking = computed(() => newDays.value !== null && newDays.value < props.trip.day_count);

function toggle(key: string) {
    const i = form.interests.indexOf(key);
    if (i === -1) form.interests.push(key);
    else form.interests.splice(i, 1);
}

function save() {
    form.transform((d) => ({ ...d, budget: d.budget === '' ? null : d.budget, travelers: Number(d.travelers) })).patch(
        route('trips.update', props.trip.id),
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <form class="panel grid gap-4 p-5 sm:grid-cols-2 sm:p-6" novalidate @submit.prevent="save">
        <p class="text-sm text-muted-foreground sm:col-span-2">
            Changing dates keeps every stop you have. Days shift with the new start date; if the trip gets shorter, stops from removed days move to
            the last day.
        </p>
        <div class="sm:col-span-2">
            <label for="d-title" class="label">Trip name</label>
            <input id="d-title" v-model="form.title" class="field" maxlength="120" />
            <p class="err">{{ form.errors.title }}</p>
        </div>
        <div>
            <label for="d-start" class="label">Start date</label>
            <input id="d-start" v-model="form.start_date" type="date" class="field" required />
            <p class="err">{{ form.errors.start_date }}</p>
        </div>
        <div>
            <label for="d-end" class="label">End date</label>
            <input id="d-end" v-model="form.end_date" type="date" class="field" :min="form.start_date" required />
            <p class="err">{{ form.errors.end_date }}</p>
        </div>
        <p v-if="shrinking" class="rounded-lg border border-terra-400 bg-terra-100 px-3 py-2 text-sm text-terra-600 sm:col-span-2" role="status">
            This shortens the trip from {{ trip.day_count }} to {{ newDays }} days. Stops on removed days will be moved to the last day (nothing is
            deleted).
        </p>
        <div>
            <label for="d-trav" class="label">Travellers</label>
            <input id="d-trav" v-model="form.travelers" type="number" min="1" max="50" class="field" required />
            <p class="err">{{ form.errors.travelers }}</p>
        </div>
        <div>
            <label for="d-cur" class="label">Currency</label>
            <select id="d-cur" v-model="form.currency" class="field" :disabled="hasExpenses">
                <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
            </select>
            <p v-if="hasExpenses" class="hint">Locked while the trip has expenses, so your entered rates stay valid.</p>
            <p class="err">{{ form.errors.currency }}</p>
        </div>
        <div>
            <label for="d-budget" class="label">Total budget</label>
            <input id="d-budget" v-model="form.budget" inputmode="decimal" class="field" placeholder="Not set" />
            <p class="err">{{ form.errors.budget }}</p>
        </div>
        <div>
            <label for="d-pace" class="label">Pace</label>
            <select id="d-pace" v-model="form.pace" class="field">
                <option v-for="x in paces" :key="x.key" :value="x.key">{{ x.label }} (~{{ x.stops }}/day)</option>
            </select>
            <p class="hint">Applies the next time you add suggestions.</p>
        </div>
        <fieldset class="sm:col-span-2">
            <legend class="label">Interests</legend>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="i in interests"
                    :key="i.key"
                    type="button"
                    class="chip px-3 py-1.5 text-sm"
                    :class="form.interests.includes(i.key) ? 'chip-on' : 'hover:bg-sage-100'"
                    :aria-pressed="form.interests.includes(i.key)"
                    @click="toggle(i.key)"
                >
                    {{ i.label }}
                </button>
            </div>
            <p class="hint">Used the next time you add or refresh suggestions. Existing stops are not changed.</p>
        </fieldset>
        <div class="sm:col-span-2">
            <label for="d-acc" class="label">Accommodation</label><input id="d-acc" v-model="form.accommodation" class="field" maxlength="160" />
        </div>
        <div>
            <label for="d-access" class="label">Accessibility considerations</label
            ><textarea id="d-access" v-model="form.accessibility" rows="2" maxlength="500" class="field" />
        </div>
        <div>
            <label for="d-diet" class="label">Dietary preferences</label
            ><textarea id="d-diet" v-model="form.dietary" rows="2" maxlength="500" class="field" />
        </div>
        <div class="sm:col-span-2">
            <label for="d-notes" class="label">Private notes</label
            ><textarea id="d-notes" v-model="form.notes" rows="3" maxlength="2000" class="field" />
            <p class="hint">Never shown on shared links.</p>
        </div>
        <div class="sm:col-span-2"><button type="submit" class="btn-primary" :disabled="form.processing">Save trip details</button></div>
    </form>
</template>
