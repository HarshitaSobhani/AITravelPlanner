<script setup lang="ts">
import Logo from '@/components/rw/Logo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <footer class="no-print mt-16 border-t border-border bg-sand-100 text-sm text-ink-soft dark:bg-card dark:text-muted-foreground">
        <div class="container-rw grid gap-8 py-10 md:grid-cols-3">
            <div>
                <Logo />
                <p class="mt-3 max-w-xs">Plan trips around your interests, pace and budget. Itineraries are editable suggestions, not bookings.</p>
            </div>
            <nav aria-label="Footer">
                <h2 class="eyebrow !font-sans">Explore</h2>
                <ul class="mt-3 space-y-2">
                    <li><Link :href="route('destinations.index')" class="hover:underline">Destinations</Link></li>
                    <li><Link :href="route('trips.create')" class="hover:underline">Plan a trip</Link></li>
                    <li><Link :href="route('login')" class="hover:underline">Log in</Link></li>
                </ul>
            </nav>
            <div>
                <h2 class="eyebrow !font-sans">Data &amp; attribution</h2>
                <ul class="mt-3 space-y-2">
                    <li>
                        Maps &copy;
                        <a class="underline" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer"
                            >OpenStreetMap</a
                        >
                        contributors
                    </li>
                    <li>
                        Weather by
                        <a class="underline" href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Open-Meteo.com</a> (CC BY 4.0)
                    </li>
                    <li>
                        Place lookup by Open-Meteo /
                        <a class="underline" href="https://www.geonames.org/" target="_blank" rel="noopener noreferrer">GeoNames</a>
                    </li>
                    <li>Curated places: RoamWise catalog. Hours and prices are not included.</li>
                </ul>
            </div>
        </div>
    </footer>
</template>
