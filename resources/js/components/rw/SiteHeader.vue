<script setup lang="ts">
import Logo from '@/components/rw/Logo.vue';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Menu, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);
const open = ref(false);

const links = computed(() => [
    { label: 'Destinations', href: route('destinations.index'), active: route().current('destinations.*') },
    { label: 'Plan a trip', href: route('trips.create'), active: route().current('trips.create') },
    ...(user.value
        ? [
              { label: 'My trips', href: route('trips.index'), active: route().current('trips.index') || route().current('trips.show') },
              { label: 'Saved', href: route('saved'), active: route().current('saved') },
          ]
        : []),
]);

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <header class="no-print sticky top-0 z-40 border-b border-border bg-background/90 backdrop-blur">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-card focus:px-3 focus:py-2"
            >Skip to content</a
        >
        <div class="container-rw flex h-16 items-center justify-between gap-4">
            <Link :href="route('home')" aria-label="RoamWise home"><Logo /></Link>

            <nav class="hidden items-center gap-1 md:flex" aria-label="Main">
                <Link
                    v-for="l in links"
                    :key="l.label"
                    :href="l.href"
                    class="rounded-lg px-3 py-2 text-sm font-medium hover:bg-muted"
                    :class="l.active ? 'bg-muted text-foreground' : 'text-muted-foreground'"
                    :aria-current="l.active ? 'page' : undefined"
                >
                    {{ l.label }}
                </Link>
            </nav>

            <div class="hidden items-center gap-2 md:flex">
                <template v-if="user">
                    <Link :href="route('profile.edit')" class="rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted">{{
                        user.name
                    }}</Link>
                    <button type="button" class="btn-outline btn-sm" @click="logout">Log out</button>
                </template>
                <template v-else>
                    <Link :href="route('login')" class="btn-ghost">Log in</Link>
                    <Link :href="route('register')" class="btn-primary btn-sm">Sign up</Link>
                </template>
            </div>

            <button
                type="button"
                class="btn-outline px-2 md:hidden"
                :aria-expanded="open"
                aria-controls="mobile-nav"
                aria-label="Menu"
                @click="open = !open"
            >
                <X v-if="open" class="h-5 w-5" /><Menu v-else class="h-5 w-5" />
            </button>
        </div>

        <nav v-show="open" id="mobile-nav" class="border-t border-border bg-background md:hidden" aria-label="Mobile">
            <div class="container-rw flex flex-col gap-1 py-3">
                <Link
                    v-for="l in links"
                    :key="l.label"
                    :href="l.href"
                    class="rounded-lg px-3 py-3 text-base font-medium hover:bg-muted"
                    :class="l.active ? 'bg-muted' : ''"
                    @click="open = false"
                >
                    {{ l.label }}
                </Link>
                <template v-if="user">
                    <Link :href="route('profile.edit')" class="rounded-lg px-3 py-3 text-base hover:bg-muted" @click="open = false"
                        >Account settings</Link
                    >
                    <button type="button" class="rounded-lg px-3 py-3 text-left text-base hover:bg-muted" @click="logout">Log out</button>
                </template>
                <template v-else>
                    <Link :href="route('login')" class="rounded-lg px-3 py-3 text-base hover:bg-muted">Log in</Link>
                    <Link :href="route('register')" class="btn-primary mt-1">Sign up</Link>
                </template>
            </div>
        </nav>
    </header>
</template>
