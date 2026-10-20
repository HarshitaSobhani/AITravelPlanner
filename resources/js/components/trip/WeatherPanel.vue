<script setup lang="ts">
import { formatDate } from '@/lib/format';
import { LoaderCircle, RefreshCw } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

interface Day {
    date: string;
    summary: string | null;
    temp_max_c: number | null;
    temp_min_c: number | null;
    precipitation_mm: number | null;
    precipitation_probability?: number | null;
}
interface Weather {
    status: 'ok' | 'partial' | 'unavailable';
    current: { time: string | null; temp_c: number; summary: string; wind_kmh: number | null } | null;
    forecast: Day[];
    historical: Day[];
    messages: string[];
    attribution: string;
}

const props = defineProps<{ tripId: number; enabled: boolean }>();
const state = ref<'idle' | 'loading' | 'error' | 'done'>('idle');
const data = ref<Weather | null>(null);
const failure = ref('');

async function load() {
    if (!props.enabled) return;
    state.value = 'loading';
    try {
        const res = await fetch(route('trips.weather', props.tripId), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (res.status === 429) throw new Error('Too many requests. Please wait a minute.');
        const json = await res.json().catch(() => null);
        if (!json) throw new Error('Weather service returned an unreadable response.');
        data.value = json;
        state.value = 'done';
    } catch (e) {
        failure.value = e instanceof Error ? e.message : 'Weather could not be loaded.';
        state.value = 'error';
    }
}
onMounted(load);
const t = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${Math.round(v)}°C`);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="max-w-2xl text-sm text-muted-foreground">
                Weather is shown only when a weather service has data for your dates. Forecast, last year's observed weather and current conditions
                are labelled separately. Nothing is estimated.
            </p>
            <button v-if="enabled" type="button" class="btn-outline btn-sm" :disabled="state === 'loading'" @click="load">
                <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" /> Refresh
            </button>
        </div>

        <p v-if="!enabled" class="panel p-5 text-sm">Weather lookups are turned off on this server. Your itinerary is unaffected.</p>
        <p v-else-if="state === 'loading'" class="panel flex items-center gap-2 p-5 text-sm" role="status">
            <LoaderCircle class="h-4 w-4 animate-spin" aria-hidden="true" /> Loading weather…
        </p>
        <p v-else-if="state === 'error'" class="panel border-terra-400 p-5 text-sm text-terra-600" role="alert">
            {{ failure }} Planning can continue without weather.
        </p>

        <template v-else-if="data">
            <ul v-if="data.messages.length" class="space-y-1 rounded-xl border border-border bg-muted p-4 text-sm" role="status">
                <li v-for="m in data.messages" :key="m">{{ m }}</li>
            </ul>

            <section v-if="data.current" class="panel p-5" aria-labelledby="w-cur">
                <h3 id="w-cur" class="text-lg font-semibold">Current conditions at the destination</h3>
                <p class="mt-1 text-2xl font-semibold">
                    {{ t(data.current.temp_c) }}
                    <span class="text-base font-normal text-muted-foreground"
                        >{{ data.current.summary
                        }}<template v-if="data.current.wind_kmh !== null"> · wind {{ data.current.wind_kmh }} km/h</template></span
                    >
                </p>
                <p class="hint">Right now, not a forecast for your trip dates.</p>
            </section>

            <section v-if="data.forecast.length" class="panel p-5" aria-labelledby="w-fc">
                <h3 id="w-fc" class="text-lg font-semibold">Forecast <span class="chip ml-2">Forecast</span></h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[30rem] text-sm">
                        <caption class="sr-only">
                            Forecast for trip days
                        </caption>
                        <thead>
                            <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                <th class="py-2 pr-3 font-medium">Date</th>
                                <th class="py-2 pr-3 font-medium">Conditions</th>
                                <th class="py-2 pr-3 text-right font-medium">High / Low</th>
                                <th class="py-2 text-right font-medium">Rain</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in data.forecast" :key="d.date" class="border-b border-border last:border-0">
                                <td class="py-2 pr-3">{{ formatDate(d.date, { weekday: 'short', day: 'numeric', month: 'short' }) }}</td>
                                <td class="py-2 pr-3">{{ d.summary ?? '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ t(d.temp_max_c) }} / {{ t(d.temp_min_c) }}</td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ d.precipitation_mm ?? '—' }} mm<template v-if="d.precipitation_probability != null">
                                        · {{ d.precipitation_probability }}%</template
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="data.historical.length" class="panel p-5" aria-labelledby="w-hi">
                <h3 id="w-hi" class="text-lg font-semibold">
                    Observed weather on the same dates last year <span class="chip ml-2">Historical, not a forecast</span>
                </h3>
                <p class="hint">What actually happened a year earlier. A rough guide only; this year will differ.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[28rem] text-sm">
                        <caption class="sr-only">
                            Historical weather
                        </caption>
                        <thead>
                            <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                <th class="py-2 pr-3 font-medium">Date (last year)</th>
                                <th class="py-2 pr-3 font-medium">Conditions</th>
                                <th class="py-2 pr-3 text-right font-medium">High / Low</th>
                                <th class="py-2 text-right font-medium">Rain</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in data.historical" :key="d.date" class="border-b border-border last:border-0">
                                <td class="py-2 pr-3">{{ formatDate(d.date) }}</td>
                                <td class="py-2 pr-3">{{ d.summary ?? '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ t(d.temp_max_c) }} / {{ t(d.temp_min_c) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ d.precipitation_mm ?? '—' }} mm</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <p v-if="data.status === 'unavailable' && !data.messages.length" class="panel p-5 text-sm">
                No weather data is available for these dates.
            </p>
            <p class="text-xs text-muted-foreground">{{ data.attribution }}</p>
        </template>
    </div>
</template>
