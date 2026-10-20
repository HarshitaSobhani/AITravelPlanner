<script setup lang="ts">
import { artGradient } from '@/lib/categories';

defineProps<{ name: string; seed?: string }>();
</script>

<template>
    <div
        class="relative flex h-full w-full items-end overflow-hidden p-4 text-white"
        :style="{ background: artGradient(seed ?? name) }"
        role="img"
        :aria-label="`${name} (illustrative colour field; no photo stored)`"
    >
        <svg class="absolute inset-0 h-full w-full opacity-20" viewBox="0 0 200 120" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0 90 Q40 60 80 85 T160 70 T200 80 V120 H0Z" fill="#fff" />
            <path d="M0 105 Q50 85 100 100 T200 95 V120 H0Z" fill="#000" opacity=".4" />
        </svg>
        <span class="relative font-display text-2xl font-semibold drop-shadow">{{ name }}</span>
    </div>
</template>
