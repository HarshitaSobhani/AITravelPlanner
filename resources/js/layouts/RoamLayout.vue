<script setup lang="ts">
import FlashMessages from '@/components/rw/FlashMessages.vue';
import SiteFooter from '@/components/rw/SiteFooter.vue';
import SiteHeader from '@/components/rw/SiteHeader.vue';

withDefaults(defineProps<{ footer?: boolean }>(), { footer: true });
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <SiteHeader />
        <FlashMessages />
        <main id="main" class="flex-1" tabindex="-1">
            <slot />
        </main>
        <SiteFooter v-if="footer" />
    </div>
</template>
