<script setup lang="ts">
import RoamLayout from '@/layouts/RoamLayout.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <RoamLayout>
        <slot />
    </RoamLayout>
</template>
