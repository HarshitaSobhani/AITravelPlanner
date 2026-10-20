<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const page = usePage<SharedData>();
const success = computed(() => page.props.flash?.success);
const error = computed(() => page.props.flash?.error);
const dismissed = ref(false);
watch([success, error], () => (dismissed.value = false));
</script>

<template>
    <div v-if="(success || error) && !dismissed" class="container-rw no-print pt-4" aria-live="polite">
        <div
            v-if="success"
            role="status"
            class="flex items-start gap-3 rounded-xl border border-sage-200 bg-sage-100 px-4 py-3 text-sm text-sage-700 dark:border-sage-700 dark:bg-sage-700/30 dark:text-sage-100"
        >
            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <p class="flex-1">{{ success }}</p>
            <button type="button" class="rounded p-1 hover:bg-black/5" aria-label="Dismiss message" @click="dismissed = true">
                <X class="h-4 w-4" />
            </button>
        </div>
        <div
            v-else-if="error"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-terra-400 bg-terra-100 px-4 py-3 text-sm text-terra-600 dark:bg-terra-600/20 dark:text-terra-100"
        >
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <p class="flex-1">{{ error }}</p>
            <button type="button" class="rounded p-1 hover:bg-black/5" aria-label="Dismiss message" @click="dismissed = true">
                <X class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>
