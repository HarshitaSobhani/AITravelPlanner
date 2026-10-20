<script setup lang="ts">
import ConfirmDialog from '@/components/rw/ConfirmDialog.vue';
import { categoryColor, categoryLabel } from '@/lib/categories';
import { addMinutes, formatDate, formatDuration, isSafeUrl } from '@/lib/format';
import type { Day, Stop } from '@/types/roam';
import { router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowDown, ArrowUp, ChevronDown, ChevronUp, ExternalLink, Lock, MapPin, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { reactive, ref } from 'vue';

const props = defineProps<{
    days: Day[];
    selectedStopId: number | null;
    readonly?: boolean;
    activeDayId?: number | null;
}>();
const emit = defineEmits<{ (e: 'select', stop: Stop): void; (e: 'add', dayId: number): void; (e: 'select-day', dayId: number): void }>();

const editing = ref<number | null>(null);
const toRemove = ref<Stop | null>(null);
const open = reactive<Record<number, boolean>>({});
const busy = ref(false);

const form = useForm({ duration_minutes: 60 as number | string, start_time: '' as string, notes: '' as string, name: '' as string });

function isOpen(d: Day) {
    return open[d.id] !== false;
}

function startEdit(s: Stop) {
    editing.value = s.id;
    form.clearErrors();
    form.duration_minutes = s.duration_minutes;
    form.start_time = s.time_locked && s.start_time ? s.start_time : '';
    form.notes = s.notes ?? '';
    form.name = s.name;
}

function save(s: Stop) {
    form.transform((d) => ({
        duration_minutes: Number(d.duration_minutes),
        start_time: d.start_time || null,
        notes: d.notes || null,
        ...(s.place_id ? {} : { name: d.name }),
    })).patch(route('stops.update', s.id), { preserveScroll: true, preserveState: true, onSuccess: () => (editing.value = null) });
}

function move(s: Stop, day: Day, delta: number) {
    router.post(route('stops.move', s.id), { itinerary_day_id: day.id, position: s.position + delta }, { preserveScroll: true, preserveState: true });
}

function moveToDay(s: Stop, dayId: string) {
    const target = props.days.find((d) => d.id === Number(dayId));
    if (!target || dayId === '') return;
    router.post(route('stops.move', s.id), { itinerary_day_id: target.id, position: target.stops.length }, { preserveScroll: true, preserveState: true });
}

function remove() {
    if (!toRemove.value) return;
    busy.value = true;
    router.delete(route('stops.destroy', toRemove.value.id), {
        preserveScroll: true, preserveState: true,
        onFinish: () => {
            busy.value = false;
            toRemove.value = null;
        },
    });
}
</script>

<template>
    <div class="space-y-5">
        <section
            v-for="day in days"
            :key="day.id"
            class="panel overflow-hidden"
            :class="activeDayId === day.id ? 'ring-2 ring-brand-500' : ''"
            :aria-labelledby="`day-${day.id}`"
        >
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-muted/50 px-4 py-3">
                <button
                    type="button"
                    class="flex items-center gap-2 text-left"
                    :aria-expanded="isOpen(day)"
                    :aria-controls="`day-body-${day.id}`"
                    @click="open[day.id] = !isOpen(day)"
                >
                    <component :is="isOpen(day) ? ChevronUp : ChevronDown" class="h-4 w-4" aria-hidden="true" />
                    <h3 :id="`day-${day.id}`" class="text-lg font-semibold">
                        Day {{ day.position + 1 }}
                        <span class="font-sans text-sm font-normal text-muted-foreground"
                            >· {{ formatDate(day.date, { weekday: 'long', day: 'numeric', month: 'short' }) }}</span
                        >
                    </h3>
                </button>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-muted-foreground">{{ day.stops.length }} {{ day.stops.length === 1 ? 'stop' : 'stops' }}</span>
                    <button type="button" class="btn-ghost btn-sm" :aria-pressed="activeDayId === day.id" @click="emit('select-day', day.id)">
                        <MapPin class="h-3.5 w-3.5" aria-hidden="true" /> {{ activeDayId === day.id ? 'Showing on map' : 'Show day on map' }}
                    </button>
                    <button v-if="!readonly" type="button" class="btn-outline btn-sm" @click="emit('add', day.id)">
                        <Plus class="h-3.5 w-3.5" aria-hidden="true" /> Add stop
                    </button>
                </div>
            </header>

            <div v-show="isOpen(day)" :id="`day-body-${day.id}`">
                <p v-if="!day.stops.length" class="p-6 text-sm text-muted-foreground">
                    No stops on this day yet.<template v-if="!readonly"> Use “Add stop”, or regenerate suggestions to fill open slots.</template>
                </p>
                <ol class="divide-y divide-border">
                    <li
                        v-for="(s, i) in day.stops"
                        :key="s.id"
                        class="p-4"
                        :class="selectedStopId === s.id ? 'bg-brand-50/60 dark:bg-brand-700/10' : ''"
                    >
                        <div class="flex gap-3">
                            <button
                                type="button"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                                :style="{ background: categoryColor(s.category) }"
                                :aria-label="`Stop ${i + 1}: ${s.name}. Show on map`"
                                @click="emit('select', s)"
                            >
                                {{ i + 1 }}
                            </button>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <h4 class="font-semibold">{{ s.name }}</h4>
                                    <p class="text-sm tabular-nums text-muted-foreground">
                                        <template v-if="s.start_time"
                                            >{{ s.start_time }}–{{ addMinutes(s.start_time, s.duration_minutes) }} · </template
                                        >{{ formatDuration(s.duration_minutes) }}
                                    </p>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{ categoryLabel(s.category) }}<template v-if="s.neighbourhood"> · {{ s.neighbourhood }}</template>
                                    <span
                                        v-if="s.source === 'manual'"
                                        class="ml-1 rounded bg-sage-100 px-1.5 py-0.5 text-sage-700 dark:bg-sage-700/30 dark:text-sage-100"
                                        >added by you</span
                                    >
                                    <span
                                        v-else-if="s.is_locked"
                                        class="ml-1 inline-flex items-center gap-0.5 rounded bg-sage-100 px-1.5 py-0.5 text-sage-700 dark:bg-sage-700/30 dark:text-sage-100"
                                        ><Lock class="h-3 w-3" aria-hidden="true" />edited</span
                                    >
                                    <span v-else class="ml-1 rounded bg-muted px-1.5 py-0.5">suggested</span>
                                    <span v-if="s.latitude === null" class="ml-1 rounded bg-muted px-1.5 py-0.5">no map location</span>
                                </p>
                                <p v-if="s.description && editing !== s.id" class="mt-1 text-sm text-muted-foreground">{{ s.description }}</p>
                                <ul v-if="s.warnings?.length" class="mt-1 space-y-0.5">
                                    <li v-for="w in s.warnings" :key="w" class="flex items-start gap-1 text-xs text-terra-600 dark:text-terra-100">
                                        <AlertTriangle class="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />{{ w }}
                                    </li>
                                </ul>
                                <p v-if="s.notes && editing !== s.id" class="mt-2 rounded-lg bg-muted px-3 py-2 text-sm">{{ s.notes }}</p>
                                <a
                                    v-if="isSafeUrl(s.website)"
                                    :href="s.website"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="mt-1 inline-flex items-center gap-1 text-xs underline"
                                    >Website <ExternalLink class="h-3 w-3" aria-hidden="true"
                                /></a>

                                <form
                                    v-if="editing === s.id"
                                    class="mt-3 grid gap-3 rounded-xl border border-border bg-background p-3 sm:grid-cols-2"
                                    @submit.prevent="save(s)"
                                >
                                    <div v-if="!s.place_id" class="sm:col-span-2">
                                        <label :for="`n-${s.id}`" class="label">Name</label>
                                        <input :id="`n-${s.id}`" v-model="form.name" class="field" maxlength="160" required />
                                        <p class="err">{{ form.errors.name }}</p>
                                    </div>
                                    <div>
                                        <label :for="`d-${s.id}`" class="label">Visit length (minutes)</label>
                                        <input
                                            :id="`d-${s.id}`"
                                            v-model="form.duration_minutes"
                                            type="number"
                                            min="5"
                                            max="720"
                                            step="5"
                                            class="field"
                                            required
                                        />
                                        <p class="err">{{ form.errors.duration_minutes }}</p>
                                    </div>
                                    <div>
                                        <label :for="`t-${s.id}`" class="label"
                                            >Start time <span class="font-normal text-muted-foreground">(optional)</span></label
                                        >
                                        <input :id="`t-${s.id}`" v-model="form.start_time" type="time" class="field" />
                                        <p class="hint">Leave empty to follow the previous stop.</p>
                                        <p class="err">{{ form.errors.start_time }}</p>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label :for="`no-${s.id}`" class="label">Notes</label>
                                        <textarea :id="`no-${s.id}`" v-model="form.notes" rows="2" maxlength="1000" class="field" />
                                        <p class="err">{{ form.errors.notes }}</p>
                                    </div>
                                    <div class="flex gap-2 sm:col-span-2">
                                        <button type="submit" class="btn-primary btn-sm" :disabled="form.processing">Save stop</button>
                                        <button type="button" class="btn-outline btn-sm" @click="editing = null">Cancel</button>
                                    </div>
                                </form>

                                <div v-if="!readonly && editing !== s.id" class="mt-3 flex flex-wrap items-center gap-1.5">
                                    <button
                                        type="button"
                                        class="btn-outline btn-sm"
                                        :disabled="i === 0"
                                        :aria-label="`Move ${s.name} earlier`"
                                        @click="move(s, day, -1)"
                                    >
                                        <ArrowUp class="h-3.5 w-3.5" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn-outline btn-sm"
                                        :disabled="i === day.stops.length - 1"
                                        :aria-label="`Move ${s.name} later`"
                                        @click="move(s, day, 1)"
                                    >
                                        <ArrowDown class="h-3.5 w-3.5" aria-hidden="true" />
                                    </button>
                                    <label :for="`mv-${s.id}`" class="sr-only">Move {{ s.name }} to another day</label>
                                    <select
                                        :id="`mv-${s.id}`"
                                        class="field !w-auto !py-1 text-xs"
                                        :disabled="days.length < 2"
                                        @change="
                                            (e) => {
                                                moveToDay(s, (e.target as HTMLSelectElement).value);
                                                (e.target as HTMLSelectElement).value = '';
                                            }
                                        "
                                    >
                                        <option value="">Move to day…</option>
                                        <option v-for="d in days.filter((x) => x.id !== day.id)" :key="d.id" :value="d.id">
                                            Day {{ d.position + 1 }}
                                        </option>
                                    </select>
                                    <button type="button" class="btn-outline btn-sm" @click="startEdit(s)">
                                        <Pencil class="h-3.5 w-3.5" aria-hidden="true" /> Edit
                                    </button>
                                    <button type="button" class="btn-danger btn-sm" :aria-label="`Remove ${s.name}`" @click="toRemove = s">
                                        <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </li>
                </ol>
            </div>
        </section>

        <ConfirmDialog
            :open="!!toRemove"
            title="Remove this stop?"
            confirm-label="Remove stop"
            danger
            :busy="busy"
            @cancel="toRemove = null"
            @confirm="remove"
        >
            “{{ toRemove?.name }}” will be removed from this day, including its notes. You can add it back from the place list.
        </ConfirmDialog>
    </div>
</template>
