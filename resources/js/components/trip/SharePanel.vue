<script setup lang="ts">
import ConfirmDialog from '@/components/rw/ConfirmDialog.vue';
import { timeAgo } from '@/lib/format';
import type { Share } from '@/types/roam';
import { router, useForm } from '@inertiajs/vue3';
import { Copy, Link2Off } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{ tripId: number; shares: Share[] }>();
const form = useForm({ include_budget_totals: false });
const toRevoke = ref<Share | null>(null);
const copied = ref<number | null>(null);
const copyFailed = ref(false);

function create() {
    form.post(route('shares.store', props.tripId), { preserveScroll: true, preserveState: true });
}

async function copy(s: Share) {
    try {
        await navigator.clipboard.writeText(s.url);
        copied.value = s.id;
        copyFailed.value = false;
        setTimeout(() => (copied.value = null), 2000);
    } catch {
        copyFailed.value = true;
    }
}

function revoke() {
    if (!toRevoke.value) return;
    router.delete(route('shares.destroy', toRevoke.value.id), { preserveScroll: true, preserveState: true, onFinish: () => (toRevoke.value = null) });
}
</script>

<template>
    <div class="space-y-6">
        <section class="panel p-5" aria-labelledby="sh">
            <h3 id="sh" class="text-lg font-semibold">Share a read-only itinerary</h3>
            <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                <strong>Anyone with the link can view</strong> the itinerary (places, days, times, map). Your trip notes, stop notes, expenses and
                account details are never shown. You can revoke a link at any time.
            </p>
            <form class="mt-4 space-y-3" @submit.prevent="create">
                <label class="flex items-start gap-2 text-sm"
                    ><input v-model="form.include_budget_totals" type="checkbox" class="mt-0.5 accent-[#216560]" /><span
                        >Also show my total estimated budget (no individual expenses)</span
                    ></label
                >
                <button type="submit" class="btn-primary" :disabled="form.processing">Create share link</button>
            </form>
        </section>

        <section class="panel p-5" aria-labelledby="sl">
            <h3 id="sl" class="text-lg font-semibold">Active links</h3>
            <p v-if="!shares.length" class="mt-2 text-sm text-muted-foreground">No active links. This trip is private.</p>
            <ul class="mt-2 divide-y divide-border">
                <li v-for="s in shares" :key="s.id" class="py-3">
                    <label :for="`su-${s.id}`" class="sr-only">Share link</label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <input
                            :id="`su-${s.id}`"
                            :value="s.url"
                            readonly
                            class="field flex-1 text-xs"
                            @focus="(e) => (e.target as HTMLInputElement).select()"
                        />
                        <button type="button" class="btn-outline btn-sm" @click="copy(s)">
                            <Copy class="h-3.5 w-3.5" aria-hidden="true" /> {{ copied === s.id ? 'Copied' : 'Copy' }}
                        </button>
                        <button type="button" class="btn-danger btn-sm" @click="toRevoke = s">
                            <Link2Off class="h-3.5 w-3.5" aria-hidden="true" /> Revoke
                        </button>
                    </div>
                    <p class="hint">
                        Created {{ timeAgo(s.created_at) }} · {{ s.last_viewed_at ? `last viewed ${timeAgo(s.last_viewed_at)}` : 'not viewed yet' }} ·
                        {{ s.include_budget_totals ? 'budget total visible' : 'no budget shown' }}
                    </p>
                </li>
            </ul>
            <p v-if="copyFailed" class="err" role="alert">Copy failed. Select the link and copy it manually.</p>
        </section>

        <ConfirmDialog :open="!!toRevoke" title="Revoke this link?" confirm-label="Revoke link" danger @cancel="toRevoke = null" @confirm="revoke"
            >People who have this link will no longer be able to open the itinerary.</ConfirmDialog
        >
    </div>
</template>
