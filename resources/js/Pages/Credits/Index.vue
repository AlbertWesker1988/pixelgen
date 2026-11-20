<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    balance: { type: String, required: true },
    packs: { type: Array, required: true },
    transactions: { type: Array, required: true },
});

const form = useForm({ amount: props.packs[0] });

function buy(amount) {
    form.amount = amount;
    form.post(route('credits.store'), { preserveScroll: true });
}

const REASON_LABEL = {
    purchase: 'Credit purchase',
    hold: 'Generation hold',
    settle_refund: 'Hold released',
};

function isCredit(amount) {
    return !String(amount).startsWith('-');
}

function formatAmount(amount) {
    const sign = isCredit(amount) ? '+' : '-';
    return `${sign}$${String(amount).replace('-', '')}`;
}

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleString() : '';
}
</script>

<template>
    <Head title="Credits" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Credits</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <p class="text-sm text-gray-500">Current balance</p>
                    <p class="mt-1 text-3xl font-bold text-gray-900">${{ balance }}</p>
                    <p class="mt-1 text-xs text-gray-400">
                        Credits are held up front at the estimated cost when you generate, then the
                        unused part is returned once the real cost is known. Failed generations are
                        never charged.
                    </p>
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-700">Buy credits</h3>
                    <p class="mt-1 text-xs text-gray-400">A fake purchase. No real payment is taken.</p>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <PrimaryButton
                            v-for="pack in packs"
                            :key="pack"
                            :disabled="form.processing"
                            @click="buy(pack)"
                        >
                            Add ${{ pack }}
                        </PrimaryButton>
                    </div>

                    <p v-if="form.recentlySuccessful" class="mt-3 text-sm text-green-600">
                        Credits added.
                    </p>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <h3 class="border-b border-gray-100 px-6 py-4 text-sm font-semibold text-gray-700">
                        Ledger
                    </h3>

                    <div
                        v-if="transactions.length === 0"
                        class="px-6 py-6 text-center text-sm text-gray-500"
                    >
                        No transactions yet.
                    </div>

                    <table v-else class="w-full text-sm">
                        <tbody>
                            <tr
                                v-for="t in transactions"
                                :key="t.id"
                                class="border-b border-gray-50 last:border-0"
                            >
                                <td class="px-6 py-3 text-gray-700">
                                    {{ t.description ?? REASON_LABEL[t.reason] }}
                                </td>
                                <td class="px-6 py-3 text-gray-400">{{ formatDate(t.created_at) }}</td>
                                <td
                                    class="px-6 py-3 text-right font-medium"
                                    :class="isCredit(t.amount) ? 'text-green-600' : 'text-red-600'"
                                >
                                    {{ formatAmount(t.amount) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
