<script setup lang="ts">
import RoamLayout from '@/layouts/RoamLayout.vue';
import { dayCount, formatDate } from '@/lib/format';
import type { Option, Pace } from '@/types/roam';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Check, ChevronLeft, ChevronRight, LoaderCircle } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

interface Dest {
    id: number;
    slug: string;
    name: string;
    country_name: string | null;
    currency_code: string | null;
}

const props = defineProps<{
    destinations: Dest[];
    selectedId: number | null;
    interests: Option[];
    paces: Pace[];
    currencies: string[];
    today: string;
    maxDays: number;
}>();

const form = useForm({
    destination_id: props.selectedId as number | null,
    title: '',
    start_date: '',
    end_date: '',
    travelers: '' as string | number,
    currency: '',
    budget: '',
    pace: '',
    interests: [] as string[],
    accessibility: '',
    dietary: '',
    accommodation: '',
    notes: '',
});

const steps = ['Destination', 'Dates & budget', 'Interests & pace', 'Preferences'];
const step = ref(props.selectedId ? 1 : 0);
const reached = ref(props.selectedId ? 1 : 0);
const filter = ref('');
const clientErrors = ref<Record<string, string>>({});
const heading = ref<HTMLElement | null>(null);

const selected = computed(() => props.destinations.find((d) => d.id === form.destination_id) ?? null);
const shown = computed(() =>
    props.destinations.filter((d) => `${d.name} ${d.country_name}`.toLowerCase().includes(filter.value.trim().toLowerCase())),
);
const days = computed(() => (form.start_date && form.end_date && form.end_date >= form.start_date ? dayCount(form.start_date, form.end_date) : null));
const err = (k: string) => clientErrors.value[k] || (form.errors as Record<string, string | undefined>)[k];

// Suggest the destination's local currency once, never overriding a choice the user made.
watch(
    () => form.destination_id,
    () => {
        const c = selected.value?.currency_code;
        if (!form.currency && c && props.currencies.includes(c)) form.currency = c;
    },
    { immediate: true },
);

const fieldStep: Record<string, number> = {
    destination_id: 0,
    start_date: 1,
    end_date: 1,
    travelers: 1,
    currency: 1,
    budget: 1,
    pace: 2,
    interests: 2,
    accessibility: 3,
    dietary: 3,
    accommodation: 3,
    notes: 3,
    title: 3,
};

function validate(s: number): boolean {
    const e: Record<string, string> = {};
    if (s === 0 && !form.destination_id) e.destination_id = 'Choose a destination to continue.';
    if (s === 1) {
        if (!form.start_date) e.start_date = 'Enter a start date.';
        else if (form.start_date < props.today) e.start_date = 'The start date cannot be in the past.';
        if (!form.end_date) e.end_date = 'Enter an end date.';
        else if (form.start_date && form.end_date < form.start_date) e.end_date = 'The end date must be on or after the start date.';
        else if (days.value && days.value > props.maxDays) e.end_date = `Trips can be at most ${props.maxDays} days long.`;
        const n = Number(form.travelers);
        if (form.travelers === '' || !Number.isInteger(n) || n < 1 || n > 50) e.travelers = 'Enter a whole number of travellers from 1 to 50.';
        if (!form.currency) e.currency = 'Choose a currency.';
        if (form.budget !== '' && !/^\d{1,12}(\.\d{1,2})?$/.test(String(form.budget)))
            e.budget = 'Enter a budget like 1500 or 1500.50, or leave it empty.';
    }
    if (s === 2 && !form.pace) e.pace = 'Choose how packed your days should be.';
    clientErrors.value = e;
    return Object.keys(e).length === 0;
}

async function go(n: number) {
    step.value = n;
    reached.value = Math.max(reached.value, n);
    await nextTick();
    heading.value?.focus();
}
function next() {
    if (validate(step.value)) go(step.value + 1);
}
function jump(n: number) {
    if (n <= reached.value) {
        clientErrors.value = {};
        go(n);
    }
}

function submit() {
    for (let s = 0; s <= 2; s++) {
        if (!validate(s)) return go(s);
    }
    form.transform((d) => ({ ...d, budget: d.budget === '' ? null : d.budget, travelers: Number(d.travelers) })).post(route('trips.store'), {
        onError: (errors) => {
            const first = Object.keys(errors)
                .map((k) => fieldStep[k.split('.')[0]] ?? 3)
                .sort()[0];
            if (first !== undefined) go(first);
        },
    });
}

function toggleInterest(key: string) {
    const i = form.interests.indexOf(key);
    if (i === -1) form.interests.push(key);
    else form.interests.splice(i, 1);
}
</script>

<template>
    <Head title="Plan a trip" />
    <RoamLayout>
        <div class="container-rw max-w-3xl py-10">
            <p class="eyebrow">New trip</p>
            <h1 class="mt-1 text-4xl font-semibold">Plan a trip</h1>

            <ol class="mt-6 flex gap-2 overflow-x-auto" aria-label="Progress">
                <li v-for="(s, i) in steps" :key="s" class="min-w-0 flex-1">
                    <button
                        type="button"
                        class="flex w-full flex-col gap-1 border-t-4 pt-2 text-left text-xs font-semibold disabled:cursor-not-allowed"
                        :class="
                            i <= step
                                ? 'border-brand-600 text-foreground'
                                : i <= reached
                                  ? 'border-brand-100 text-muted-foreground'
                                  : 'border-border text-muted-foreground'
                        "
                        :disabled="i > reached"
                        :aria-current="i === step ? 'step' : undefined"
                        @click="jump(i)"
                    >
                        <span class="text-[0.65rem] uppercase tracking-wider">Step {{ i + 1 }}</span>
                        <span class="truncate text-sm">{{ s }}</span>
                    </button>
                </li>
            </ol>

            <form class="panel mt-6 p-5 sm:p-8" novalidate @submit.prevent="step === steps.length - 1 ? submit() : next()">
                <h2 ref="heading" tabindex="-1" class="text-2xl font-semibold outline-none">{{ steps[step] }}</h2>

                <!-- Every step stays mounted (v-show) so values are kept while navigating. -->
                <fieldset v-show="step === 0" class="mt-4">
                    <legend class="sr-only">Destination</legend>
                    <label for="dfilter" class="label">Search the curated destinations</label>
                    <input id="dfilter" v-model="filter" type="search" class="field" placeholder="Filter by name or country" />
                    <p v-if="err('destination_id')" class="err" role="alert">{{ err('destination_id') }}</p>
                    <p v-if="!destinations.length" class="mt-4 text-sm text-muted-foreground">No destinations are loaded. Run the database seeder.</p>
                    <ul class="mt-3 grid max-h-80 gap-2 overflow-y-auto sm:grid-cols-2" role="radiogroup" aria-label="Destinations">
                        <li v-for="d in shown" :key="d.id">
                            <label
                                class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm hover:bg-muted has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 dark:has-[:checked]:bg-brand-700/20"
                            >
                                <input v-model="form.destination_id" type="radio" name="destination" :value="d.id" class="h-4 w-4 accent-[#216560]" />
                                <span
                                    ><span class="block font-semibold">{{ d.name }}</span
                                    ><span class="text-xs text-muted-foreground">{{ d.country_name }}</span></span
                                >
                            </label>
                        </li>
                    </ul>
                    <p v-if="filter && !shown.length" class="mt-3 text-sm text-muted-foreground">
                        No match. <Link :href="route('destinations.index')" class="underline">Look up other cities online</Link> from the destinations
                        page.
                    </p>
                </fieldset>

                <div v-show="step === 1" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="start" class="label">Start date</label>
                        <input
                            id="start"
                            v-model="form.start_date"
                            type="date"
                            :min="today"
                            class="field"
                            :aria-invalid="!!err('start_date')"
                            aria-describedby="start-err"
                        />
                        <p id="start-err" class="err" role="alert">{{ err('start_date') }}</p>
                    </div>
                    <div>
                        <label for="end" class="label">End date</label>
                        <input
                            id="end"
                            v-model="form.end_date"
                            type="date"
                            :min="form.start_date || today"
                            class="field"
                            :aria-invalid="!!err('end_date')"
                            aria-describedby="end-err"
                        />
                        <p id="end-err" class="err" role="alert">{{ err('end_date') }}</p>
                    </div>
                    <p v-if="days" class="text-sm text-muted-foreground sm:col-span-2" role="status">
                        {{ days }} {{ days === 1 ? 'day' : 'days' }} · {{ formatDate(form.start_date) }} to {{ formatDate(form.end_date) }}
                    </p>
                    <div>
                        <label for="travelers" class="label">Number of travellers</label>
                        <input
                            id="travelers"
                            v-model="form.travelers"
                            type="number"
                            min="1"
                            max="50"
                            inputmode="numeric"
                            class="field"
                            :aria-invalid="!!err('travelers')"
                        />
                        <p class="err" role="alert">{{ err('travelers') }}</p>
                    </div>
                    <div>
                        <label for="currency" class="label">Currency</label>
                        <select id="currency" v-model="form.currency" class="field" :aria-invalid="!!err('currency')">
                            <option value="" disabled>Choose…</option>
                            <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <p class="err" role="alert">{{ err('currency') }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="budget" class="label">Total budget <span class="font-normal text-muted-foreground">(optional)</span></label>
                        <input
                            id="budget"
                            v-model="form.budget"
                            type="text"
                            inputmode="decimal"
                            class="field"
                            placeholder="e.g. 1500"
                            :aria-invalid="!!err('budget')"
                            aria-describedby="budget-hint"
                        />
                        <p id="budget-hint" class="hint">Your own limit for the whole group. You can leave it empty and add it later.</p>
                        <p class="err" role="alert">{{ err('budget') }}</p>
                    </div>
                </div>

                <div v-show="step === 2" class="mt-4 space-y-6">
                    <fieldset>
                        <legend class="label">Interests <span class="font-normal text-muted-foreground">(optional, pick any)</span></legend>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                v-for="i in interests"
                                :key="i.key"
                                type="button"
                                class="chip px-4 py-2 text-sm"
                                :class="form.interests.includes(i.key) ? 'chip-on' : 'hover:bg-sage-100'"
                                :aria-pressed="form.interests.includes(i.key)"
                                @click="toggleInterest(i.key)"
                            >
                                <Check v-if="form.interests.includes(i.key)" class="h-3.5 w-3.5" aria-hidden="true" />{{ i.label }}
                            </button>
                        </div>
                        <p class="err" role="alert">{{ err('interests') }}</p>
                    </fieldset>
                    <fieldset>
                        <legend class="label">Travel pace</legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-3">
                            <label
                                v-for="p in paces"
                                :key="p.key"
                                class="flex cursor-pointer flex-col rounded-xl border p-4 text-sm hover:bg-muted has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 dark:has-[:checked]:bg-brand-700/20"
                            >
                                <span class="flex items-center gap-2 font-semibold"
                                    ><input v-model="form.pace" type="radio" name="pace" :value="p.key" class="accent-[#216560]" />{{ p.label }}</span
                                >
                                <span class="mt-1 text-xs text-muted-foreground">About {{ p.stops }} stops per day</span>
                            </label>
                        </div>
                        <p class="err" role="alert">{{ err('pace') }}</p>
                    </fieldset>
                </div>

                <div v-show="step === 3" class="mt-4 grid gap-4">
                    <p class="text-sm text-muted-foreground">All optional. Anything you add stays private to your account.</p>
                    <div>
                        <label for="title" class="label">Trip name</label>
                        <input
                            id="title"
                            v-model="form.title"
                            type="text"
                            maxlength="120"
                            class="field"
                            :placeholder="selected ? `Trip to ${selected.name}` : 'Trip name'"
                        />
                        <p class="err" role="alert">{{ err('title') }}</p>
                    </div>
                    <div>
                        <label for="accommodation" class="label">Where will you stay? (area or hotel name)</label>
                        <input id="accommodation" v-model="form.accommodation" type="text" maxlength="160" class="field" />
                        <p class="hint">Used as a label only. Coordinates are not looked up automatically.</p>
                    </div>
                    <div>
                        <label for="access" class="label">Accessibility or mobility considerations</label>
                        <textarea id="access" v-model="form.accessibility" rows="2" maxlength="500" class="field" />
                        <p class="hint">Stored with your trip. The planner does not yet filter places by accessibility; check each venue.</p>
                    </div>
                    <div>
                        <label for="diet" class="label">Dietary preferences</label>
                        <textarea id="diet" v-model="form.dietary" rows="2" maxlength="500" class="field" />
                    </div>
                    <div>
                        <label for="notes" class="label">Notes</label>
                        <textarea id="notes" v-model="form.notes" rows="3" maxlength="2000" class="field" />
                        <p class="err" role="alert">{{ err('notes') }}</p>
                    </div>
                    <p v-if="Object.keys(form.errors).length" class="err" role="alert">Some details need fixing. Check the earlier steps.</p>
                </div>

                <div class="mt-8 flex items-center justify-between gap-3">
                    <button v-if="step > 0" type="button" class="btn-outline" @click="jump(step - 1)">
                        <ChevronLeft class="h-4 w-4" aria-hidden="true" /> Back
                    </button>
                    <span v-else />
                    <button v-if="step < steps.length - 1" type="submit" class="btn-primary">
                        Next <ChevronRight class="h-4 w-4" aria-hidden="true" />
                    </button>
                    <button v-else type="submit" class="btn-terra" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" aria-hidden="true" /> Create trip &amp; plan
                    </button>
                </div>
            </form>
        </div>
    </RoamLayout>
</template>
