<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    generations: { type: Array, required: true },
});

const FINAL = ['succeeded', 'failed', 'create_failed'];

const hasPending = computed(() => props.generations.some((g) => !FINAL.includes(g.status)));

/**
 * Inertia is server-driven, so "live status" is a partial reload: while any
 * generation is not final we re-fetch just the `generations` prop every few
 * seconds. Webhooks move the real state; this only pulls the fresh view.
 */
let timer = null;

onMounted(() => {
    timer = setInterval(() => {
        if (hasPending.value) {
            router.reload({ only: ['generations'] });
        }
    }, 3000);
});

onUnmounted(() => clearInterval(timer));

function badgeClass(status) {
    return {
        creating: 'bg-gray-100 text-gray-700',
        queued: 'bg-gray-100 text-gray-700',
        processing: 'bg-blue-100 text-blue-700',
        succeeded: 'bg-green-100 text-green-700',
        failed: 'bg-red-100 text-red-700',
        create_failed: 'bg-red-100 text-red-700',
    }[status] ?? 'bg-gray-100 text-gray-700';
}

function money(value) {
    return value === null || value === undefined ? '—' : `$${value}`;
}
</script>

<template>
    <Head title="History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Your generations</h2>
                <Link
                    :href="route('generations.create')"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                >
                    New generation
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                <div
                    v-if="generations.length === 0"
                    class="rounded-lg bg-white p-6 text-center text-gray-500 shadow-sm"
                >
                    Nothing yet. Create your first generation.
                </div>

                <div
                    v-for="g in generations"
                    :key="g.id"
                    class="flex gap-4 overflow-hidden rounded-lg bg-white p-4 shadow-sm"
                >
                    <div class="h-24 w-24 flex-shrink-0 overflow-hidden rounded-md bg-gray-100">
                        <img
                            v-if="g.image_url"
                            :src="g.image_url"
                            :alt="g.prompt"
                            class="h-full w-full object-cover"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center text-xs text-gray-400">
                            no image
                        </div>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <p class="truncate text-sm font-medium text-gray-900">{{ g.prompt }}</p>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                :class="badgeClass(g.status)"
                            >
                                {{ g.status.replace('_', ' ') }}
                            </span>
                        </div>

                        <p class="mt-1 text-xs text-gray-500">{{ g.size }} · {{ g.quality }}</p>

                        <div class="mt-2 flex gap-6 text-sm">
                            <div>
                                <span class="text-gray-400">Estimated</span>
                                <span class="ml-1 text-gray-700">{{ money(g.estimated_user_cost) }}</span>
                            </div>
                            <div>
                                <span class="text-gray-400">Actual</span>
                                <span class="ml-1 font-medium text-gray-900">{{ money(g.user_cost) }}</span>
                            </div>
                        </div>

                        <p v-if="g.error" class="mt-1 text-xs text-red-600">
                            {{ g.error.message ?? g.error.code }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
