<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    report: { type: Object, required: true },
    range: { type: Object, required: true },
});

function money(value) {
    return value === null || value === undefined ? '—' : `$${value}`;
}
</script>

<template>
    <Head title="Admin report" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Spend &amp; profit report
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <!-- Totals -->
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <p class="text-sm text-gray-500">Credit spend</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900">{{ money(report.totals.spend) }}</p>
                        <p class="text-xs text-gray-400">What users were charged</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <p class="text-sm text-gray-500">Provider cost</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900">{{ money(report.totals.provider_cost) }}</p>
                        <p class="text-xs text-gray-400">What Pixelforge charged us</p>
                    </div>
                    <div class="rounded-lg bg-white p-5 shadow-sm">
                        <p class="text-sm text-gray-500">Profit</p>
                        <p class="mt-1 text-2xl font-bold text-green-700">{{ money(report.totals.profit) }}</p>
                        <p class="text-xs text-gray-400">Spend minus provider cost</p>
                    </div>
                </div>

                <p class="text-xs text-gray-400">
                    UTC days {{ range.start }} to {{ range.end }}. Provider cost is reconciled against
                    Pixelforge's GET /v1/usage.
                </p>

                <div
                    v-if="report.days.length === 0"
                    class="rounded-lg bg-white p-6 text-center text-gray-500 shadow-sm"
                >
                    No billed generations yet.
                </div>

                <!-- Per day -->
                <div
                    v-for="day in report.days"
                    :key="day.date"
                    class="overflow-hidden rounded-lg bg-white shadow-sm"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-6 py-4">
                        <h3 class="text-sm font-semibold text-gray-800">{{ day.date }}</h3>
                        <div class="flex items-center gap-3 text-sm">
                            <span class="text-gray-500">
                                Spend <span class="font-medium text-gray-900">{{ money(day.spend) }}</span>
                            </span>
                            <span class="text-gray-500">
                                Provider <span class="font-medium text-gray-900">{{ money(day.provider_cost) }}</span>
                            </span>
                            <span class="text-gray-500">
                                Profit <span class="font-medium text-green-700">{{ money(day.profit) }}</span>
                            </span>
                            <span
                                v-if="day.reconciled === true"
                                class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700"
                                :title="`Pixelforge reports ${money(day.provider_reported)}`"
                            >
                                reconciled
                            </span>
                            <span
                                v-else-if="day.reconciled === false"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700"
                                :title="`Pixelforge reports ${money(day.provider_reported)}`"
                            >
                                mismatch: provider {{ money(day.provider_reported) }}
                            </span>
                            <span
                                v-else
                                class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-500"
                            >
                                no usage data
                            </span>
                        </div>
                    </div>

                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-400">
                                <th class="px-6 py-2 font-medium">User</th>
                                <th class="px-6 py-2 text-right font-medium">Images</th>
                                <th class="px-6 py-2 text-right font-medium">Spend</th>
                                <th class="px-6 py-2 text-right font-medium">Provider</th>
                                <th class="px-6 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="user in day.users"
                                :key="user.email"
                                class="border-t border-gray-50"
                            >
                                <td class="px-6 py-3">
                                    <div class="font-medium text-gray-900">{{ user.name }}</div>
                                    <div class="text-xs text-gray-400">{{ user.email }}</div>
                                </td>
                                <td class="px-6 py-3 text-right text-gray-700">{{ user.count }}</td>
                                <td class="px-6 py-3 text-right text-gray-700">{{ money(user.spend) }}</td>
                                <td class="px-6 py-3 text-right text-gray-700">{{ money(user.provider_cost) }}</td>
                                <td class="px-6 py-3 text-right font-medium text-green-700">{{ money(user.profit) }}</td>
                            </tr>
                            <tr v-if="day.users.length === 0" class="border-t border-gray-50">
                                <td colspan="5" class="px-6 py-3 text-xs text-gray-400">
                                    No local records for this day, but Pixelforge reports
                                    {{ money(day.provider_reported) }} billed.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
