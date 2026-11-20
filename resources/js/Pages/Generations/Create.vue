<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    pricing: { type: Object, required: true },
    markup: { type: String, required: true },
    balance: { type: String, required: true },
});

const SIZES = ['1024x1024', '1536x1024', '1024x1536'];
const QUALITIES = ['low', 'medium', 'high'];

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

function addReference() {
    if (form.reference_images.length < 4) {
        form.reference_images.push('');
    }
}

function removeReference(index) {
    form.reference_images.splice(index, 1);
}

/**
 * Upper-bound cost preview. Mirrors the server's CostCalculator: text gets the
 * +15% ceiling and reasoning uses the quality max, so the real charge is never
 * higher than what we show. The server value is the one we actually store.
 */
const estimatedCost = computed(() => {
    const { rates, tokens: model } = props.pricing;
    const chars = form.prompt.length;
    const baseText = Math.ceil(chars / model.characters_per_text_token);
    const images = form.reference_images.filter((url) => url.trim() !== '').length;

    const tokens = {
        input_text_tokens: Math.ceil(baseText * (1 + model.text_token_variance)),
        input_image_tokens: images * model.per_reference_image,
        output_tokens: model.output[form.quality][form.size],
        reasoning_tokens: model.reasoning[form.quality].max,
    };

    let provider = 0;
    for (const type of Object.keys(rates)) {
        provider += (tokens[type] / 1_000_000) * parseFloat(rates[type]);
    }

    return provider * (1 + parseFloat(props.markup));
});

const formattedEstimate = computed(() => `$${estimatedCost.value.toFixed(6)}`);

/** Client-side hint only; the server re-checks and is the real gate. */
const canAfford = computed(() => parseFloat(props.balance) >= estimatedCost.value);

function submit() {
    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images.filter((url) => url.trim() !== ''),
    })).post(route('generations.store'));
}
</script>

<template>
    <Head title="Generate" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Generate an image
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <form
                    @submit.prevent="submit"
                    class="space-y-6 overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg"
                >
                    <div>
                        <InputLabel for="prompt" value="Prompt" />
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="4"
                            maxlength="4000"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Describe the image. Tip: add [fail], [slow] or [duplicate] to test error handling."
                        />
                        <p class="mt-1 text-xs text-gray-500">{{ form.prompt.length }} / 4000</p>
                        <InputError class="mt-1" :message="form.errors.prompt" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="size" value="Size" />
                            <select
                                id="size"
                                v-model="form.size"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="size in SIZES" :key="size" :value="size">{{ size }}</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.size" />
                        </div>

                        <div>
                            <InputLabel for="quality" value="Quality" />
                            <select
                                id="quality"
                                v-model="form.quality"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="quality in QUALITIES" :key="quality" :value="quality">{{ quality }}</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.quality" />
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <InputLabel value="Reference image URLs" />
                            <button
                                type="button"
                                @click="addReference"
                                :disabled="form.reference_images.length >= 4"
                                class="text-sm text-indigo-600 hover:text-indigo-800 disabled:opacity-40"
                            >
                                + Add
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Up to 4. Each one adds 765 input image tokens.</p>

                        <div
                            v-for="(url, index) in form.reference_images"
                            :key="index"
                            class="mt-2 flex items-center gap-2"
                        >
                            <TextInput
                                v-model="form.reference_images[index]"
                                type="url"
                                class="block w-full"
                                placeholder="https://example.com/image.png"
                            />
                            <button
                                type="button"
                                @click="removeReference(index)"
                                class="text-gray-400 hover:text-red-600"
                            >
                                &times;
                            </button>
                        </div>
                        <InputError class="mt-1" :message="form.errors.reference_images" />
                    </div>

                    <InputError :message="form.errors.credits" />

                    <div class="flex items-center justify-between border-t border-gray-100 pt-4">
                        <div>
                            <p class="text-sm text-gray-500">Estimated cost</p>
                            <p class="text-lg font-semibold text-gray-900">{{ formattedEstimate }}</p>
                            <p class="text-xs text-gray-400">Upper bound. You are never charged more than this.</p>
                            <p class="mt-1 text-xs" :class="canAfford ? 'text-gray-400' : 'text-red-600'">
                                Balance ${{ balance }}.
                                <Link
                                    v-if="!canAfford"
                                    :href="route('credits.index')"
                                    class="font-semibold underline"
                                >
                                    Buy credits
                                </Link>
                            </p>
                        </div>
                        <div class="flex items-center gap-4">
                            <Link :href="route('generations.index')" class="text-sm text-gray-600 hover:text-gray-900">
                                History
                            </Link>
                            <PrimaryButton :disabled="form.processing || !canAfford">Generate</PrimaryButton>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
