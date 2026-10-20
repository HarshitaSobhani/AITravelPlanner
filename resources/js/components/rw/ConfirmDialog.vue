<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    title: string;
    confirmLabel?: string;
    danger?: boolean;
    busy?: boolean;
}>();
const emit = defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>();

const dialog = ref<HTMLDialogElement | null>(null);

watch(
    () => props.open,
    async (open) => {
        await nextTick();
        const el = dialog.value;
        if (!el) return;
        if (open && !el.open) el.showModal();
        if (!open && el.open) el.close();
    },
    { immediate: true },
);
</script>

<template>
    <dialog
        ref="dialog"
        class="w-[min(28rem,calc(100vw-2rem))] rounded-2xl border border-border bg-card p-0 text-foreground shadow-xl backdrop:bg-black/40"
        :aria-label="title"
        @cancel.prevent="emit('cancel')"
        @click.self="emit('cancel')"
    >
        <div class="p-6">
            <h2 class="text-lg font-semibold">{{ title }}</h2>
            <div class="mt-2 text-sm text-muted-foreground"><slot /></div>
            <div class="mt-6 flex flex-wrap justify-end gap-2">
                <button type="button" class="btn-outline" @click="emit('cancel')">Cancel</button>
                <button type="button" :class="danger ? 'btn-terra' : 'btn-primary'" :disabled="busy" @click="emit('confirm')">
                    {{ confirmLabel ?? 'Confirm' }}
                </button>
            </div>
        </div>
    </dialog>
</template>
