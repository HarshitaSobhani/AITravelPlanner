<script setup lang="ts">
import ConfirmDialog from '@/components/rw/ConfirmDialog.vue';
import { formatMoney } from '@/lib/format';
import type { Budget, Expense, TripData } from '@/types/roam';
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    trip: TripData;
    budget: Budget;
    expenses: Expense[];
    categories: { id: number; key: string; name: string }[];
    currencies: string[];
}>();

const m = (v: number | null) => formatMoney(v, props.budget.currency, props.budget.exponent);
const editingId = ref<number | null>(null);
const showForm = ref(false);
const toDelete = ref<Expense | null>(null);

const form = useForm({
    expense_category_id: '' as string | number,
    description: '',
    currency: props.trip.currency,
    estimated: '',
    actual: '',
    manual_rate: '',
    rate_note: '',
});

const foreign = computed(() => form.currency !== props.trip.currency);
const maxCat = computed(() => Math.max(1, ...props.budget.categories.map((c) => Math.max(c.estimated_minor, c.actual_minor))));
const overBudget = computed(() => props.budget.remaining_minor !== null && props.budget.remaining_minor < 0);

function openNew() {
    editingId.value = null;
    form.clearErrors();
    form.expense_category_id = '';
    form.description = '';
    form.currency = props.trip.currency;
    form.estimated = '';
    form.actual = '';
    form.manual_rate = '';
    form.rate_note = '';
    showForm.value = true;
}

function openEdit(e: Expense) {
    editingId.value = e.id;
    form.clearErrors();
    form.expense_category_id = e.category_id;
    form.description = e.description;
    form.currency = e.currency;
    form.estimated = e.estimated_input;
    form.actual = e.actual_input;
    form.manual_rate = e.manual_rate ?? '';
    form.rate_note = e.rate_note ?? '';
    showForm.value = true;
}

function save() {
    const opts = { preserveScroll: true, preserveState: true, onSuccess: () => (showForm.value = false) };
    const t = form.transform((d) => ({
        ...d,
        actual: d.actual || null,
        manual_rate: foreign.value ? d.manual_rate : null,
        rate_note: foreign.value ? d.rate_note || null : null,
    }));
    if (editingId.value) t.patch(route('expenses.update', editingId.value), opts);
    else t.post(route('expenses.store', props.trip.id), opts);
}

function remove() {
    if (!toDelete.value) return;
    router.delete(route('expenses.destroy', toDelete.value.id), { preserveScroll: true, preserveState: true, onFinish: () => (toDelete.value = null) });
}
</script>

<template>
    <div class="space-y-6">
        <section aria-labelledby="bt" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <h3 id="bt" class="sr-only">Budget summary</h3>
            <div class="panel p-4">
                <p class="text-xs text-muted-foreground">Your trip budget</p>
                <p class="mt-1 text-2xl font-semibold">{{ budget.budget_minor === null ? 'Not set' : m(budget.budget_minor) }}</p>
                <p class="hint">Entered by you</p>
            </div>
            <div class="panel p-4">
                <p class="text-xs text-muted-foreground">Total estimated</p>
                <p class="mt-1 text-2xl font-semibold">{{ m(budget.estimated_minor) }}</p>
                <p class="hint">Calculated from your estimates · {{ m(budget.estimated_per_traveler_minor) }} per traveller</p>
            </div>
            <div class="panel p-4">
                <p class="text-xs text-muted-foreground">Actual spent</p>
                <p class="mt-1 text-2xl font-semibold">{{ m(budget.actual_minor) }}</p>
                <p class="hint">Sum of actuals you recorded</p>
            </div>
            <div class="panel p-4" :class="overBudget ? 'border-terra-500' : ''">
                <p class="text-xs text-muted-foreground">Remaining vs estimates</p>
                <p class="mt-1 text-2xl font-semibold" :class="overBudget ? 'text-terra-600' : ''">{{ m(budget.remaining_minor) }}</p>
                <p class="hint">
                    {{
                        budget.budget_minor === null
                            ? 'Set a budget in Trip details to see this.'
                            : overBudget
                              ? 'Estimates exceed your budget.'
                              : 'Budget minus estimates'
                    }}
                </p>
            </div>
        </section>

        <p v-if="budget.excluded_without_rate" class="rounded-xl border border-terra-400 bg-terra-100 px-4 py-3 text-sm text-terra-600" role="alert">
            {{ budget.excluded_without_rate }} expense(s) in another currency have no exchange rate and are left out of totals. Edit them and enter
            the rate you want to use.
        </p>

        <section class="panel p-5" aria-labelledby="bc">
            <h3 id="bc" class="text-lg font-semibold">By category</h3>
            <p class="hint">
                Estimated and actual amounts in {{ budget.currency }}. Values are only what you entered; RoamWise doesn't look up prices.
            </p>
            <ul class="mt-4 space-y-3">
                <li v-for="c in budget.categories" :key="c.key">
                    <div class="flex justify-between text-sm">
                        <span class="font-medium">{{ c.name }}</span
                        ><span class="tabular-nums"
                            >{{ m(c.estimated_minor) }} <span class="text-muted-foreground">est.</span
                            ><template v-if="c.actual_minor">
                                · {{ m(c.actual_minor) }} <span class="text-muted-foreground">actual</span></template
                            ></span
                        >
                    </div>
                    <div class="mt-1 h-2 rounded-full bg-muted" aria-hidden="true">
                        <div class="h-2 rounded-full bg-brand-600" :style="{ width: (c.estimated_minor / maxCat) * 100 + '%' }" />
                    </div>
                    <div v-if="c.actual_minor" class="mt-1 h-2 rounded-full bg-muted" aria-hidden="true">
                        <div class="h-2 rounded-full bg-terra-500" :style="{ width: (c.actual_minor / maxCat) * 100 + '%' }" />
                    </div>
                </li>
            </ul>
            <p class="mt-3 flex gap-4 text-xs text-muted-foreground">
                <span class="flex items-center gap-1"><span class="h-2 w-4 rounded bg-brand-600" /> Estimated</span
                ><span class="flex items-center gap-1"><span class="h-2 w-4 rounded bg-terra-500" /> Actual</span>
            </p>
        </section>

        <section class="panel p-5" aria-labelledby="be">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 id="be" class="text-lg font-semibold">Expenses</h3>
                <button type="button" class="btn-primary btn-sm" @click="openNew"><Plus class="h-3.5 w-3.5" aria-hidden="true" /> Add expense</button>
            </div>

            <form v-if="showForm" class="mt-4 grid gap-3 rounded-xl border border-border bg-background p-4 sm:grid-cols-2" @submit.prevent="save">
                <h4 class="text-sm font-semibold sm:col-span-2">{{ editingId ? 'Edit expense' : 'New expense' }}</h4>
                <div>
                    <label for="ex-cat" class="label">Category</label>
                    <select id="ex-cat" v-model="form.expense_category_id" class="field" required>
                        <option value="" disabled>Choose…</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                    <p class="err">{{ form.errors.expense_category_id }}</p>
                </div>
                <div>
                    <label for="ex-desc" class="label">Description</label>
                    <input id="ex-desc" v-model="form.description" class="field" maxlength="160" required />
                    <p class="err">{{ form.errors.description }}</p>
                </div>
                <div>
                    <label for="ex-cur" class="label">Currency</label>
                    <select id="ex-cur" v-model="form.currency" class="field">
                        <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
                    </select>
                    <p class="err">{{ form.errors.currency }}</p>
                </div>
                <div>
                    <label for="ex-est" class="label">Estimated amount</label>
                    <input id="ex-est" v-model="form.estimated" inputmode="decimal" class="field" placeholder="0.00" required />
                    <p class="err">{{ form.errors.estimated }}</p>
                </div>
                <div>
                    <label for="ex-act" class="label">Actual amount <span class="font-normal text-muted-foreground">(optional)</span></label>
                    <input id="ex-act" v-model="form.actual" inputmode="decimal" class="field" placeholder="0.00" />
                    <p class="err">{{ form.errors.actual }}</p>
                </div>
                <template v-if="foreign">
                    <div>
                        <label for="ex-rate" class="label">Your exchange rate</label>
                        <input id="ex-rate" v-model="form.manual_rate" inputmode="decimal" class="field" placeholder="e.g. 0.92" required />
                        <p class="hint">1 {{ form.currency }} = ? {{ trip.currency }}. RoamWise never assumes a rate.</p>
                        <p class="err">{{ form.errors.manual_rate }}</p>
                    </div>
                    <div>
                        <label for="ex-rn" class="label">Rate source <span class="font-normal text-muted-foreground">(optional)</span></label>
                        <input id="ex-rn" v-model="form.rate_note" class="field" maxlength="160" placeholder="e.g. my bank, 1 May" />
                    </div>
                </template>
                <div class="flex gap-2 sm:col-span-2">
                    <button type="submit" class="btn-primary btn-sm" :disabled="form.processing">
                        {{ editingId ? 'Save changes' : 'Add expense' }}
                    </button>
                    <button type="button" class="btn-outline btn-sm" @click="showForm = false">Cancel</button>
                </div>
            </form>

            <p v-if="!expenses.length && !showForm" class="mt-4 rounded-xl bg-muted p-5 text-sm text-muted-foreground">
                No expenses yet. Add estimates for accommodation, food, transport and more. Prices are yours to enter; none are fetched.
            </p>

            <div v-if="expenses.length" class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[34rem] text-sm">
                    <caption class="sr-only">
                        Expenses
                    </caption>
                    <thead>
                        <tr class="border-b border-border text-left text-xs text-muted-foreground">
                            <th class="py-2 pr-3 font-medium">Item</th>
                            <th class="py-2 pr-3 font-medium">Category</th>
                            <th class="py-2 pr-3 text-right font-medium">Estimated</th>
                            <th class="py-2 pr-3 text-right font-medium">Actual</th>
                            <th class="py-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in expenses" :key="e.id" class="border-b border-border last:border-0">
                            <td class="py-2 pr-3 font-medium">
                                {{ e.description
                                }}<span v-if="e.currency !== trip.currency" class="block text-xs font-normal text-muted-foreground"
                                    >Rate {{ e.manual_rate }} {{ trip.currency }}/{{ e.currency }} (entered by you)<template v-if="e.rate_note">
                                        · {{ e.rate_note }}</template
                                    ></span
                                >
                            </td>
                            <td class="py-2 pr-3 text-muted-foreground">{{ e.category }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ formatMoney(e.estimated_minor, e.currency, e.exponent) }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ e.actual_minor === null ? '—' : formatMoney(e.actual_minor, e.currency, e.exponent) }}
                            </td>
                            <td class="whitespace-nowrap py-2 text-right">
                                <button type="button" class="btn-ghost btn-sm" :aria-label="`Edit ${e.description}`" @click="openEdit(e)">
                                    <Pencil class="h-3.5 w-3.5" />
                                </button>
                                <button type="button" class="btn-ghost btn-sm" :aria-label="`Delete ${e.description}`" @click="toDelete = e">
                                    <Trash2 class="h-3.5 w-3.5" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <ConfirmDialog
            :open="!!toDelete"
            title="Delete this expense?"
            confirm-label="Delete expense"
            danger
            @cancel="toDelete = null"
            @confirm="remove"
            >“{{ toDelete?.description }}” will be removed from your budget.</ConfirmDialog
        >
    </div>
</template>
