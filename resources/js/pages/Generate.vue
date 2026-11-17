<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];

const props = defineProps<{
    balance: string;
    sizes: string[];
    qualities: string[];
}>();

const balance = computed(() => props.balance);

const form = useForm<{
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
}>({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

interface Estimate {
    estimated: string;
    min: string;
    max: string;
    tokens: Record<string, number>;
}

const estimate = ref<Estimate | null>(null);
const estimating = ref(false);
let debounce: ReturnType<typeof setTimeout> | undefined;

watch(
    () => [form.prompt, form.size, form.quality, [...form.reference_images]],
    () => {
        clearTimeout(debounce);
        debounce = setTimeout(fetchEstimate, 350);
    },
    { deep: true },
);

function xsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function fetchEstimate(): Promise<void> {
    estimating.value = true;
    try {
        const response = await fetch('/generations/estimate', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: form.reference_images.filter((u) => u.trim() !== ''),
            }),
        });
        estimate.value = response.ok ? ((await response.json()) as Estimate) : null;
    } catch {
        estimate.value = null;
    } finally {
        estimating.value = false;
    }
}

fetchEstimate();

const insufficient = computed(() => {
    if (!estimate.value || balance.value === undefined) return false;
    return Number(balance.value) < Number(estimate.value.max);
});

const qualityHints: Record<string, string> = {
    low: 'Cheapest — drafts & thumbnails',
    medium: 'Everyday quality',
    high: 'Best detail, highest cost',
};

function addReference(): void {
    if (form.reference_images.length < 4) form.reference_images.push('');
}

function removeReference(index: number): void {
    form.reference_images.splice(index, 1);
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images.filter((u) => u.trim() !== ''),
    })).post('/generations');
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto grid w-full max-w-5xl gap-6 p-4 md:grid-cols-[1fr_320px]">
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <Heading
                    title="Generate an image"
                    description="Describe the image you want. You're charged the provider's token cost plus a 25% markup — only when it succeeds."
                />

                <div class="grid gap-2">
                    <Label for="prompt">Prompt</Label>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="4"
                        maxlength="4000"
                        placeholder="A lighthouse on a cliff at dawn, oil painting…"
                        class="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-24 w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                    />
                    <div class="text-muted-foreground flex justify-between text-xs">
                        <span>{{ form.prompt.length }} / 4000</span>
                    </div>
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid gap-2">
                    <Label>Size</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="option in sizes"
                            :key="option"
                            type="button"
                            :class="[
                                'rounded-md border px-3 py-1.5 font-mono text-sm',
                                form.size === option ? 'border-primary bg-primary text-primary-foreground' : 'bg-background hover:bg-muted',
                            ]"
                            @click="form.size = option"
                        >
                            {{ option }}
                        </button>
                    </div>
                    <InputError :message="form.errors.size" />
                </div>

                <div class="grid gap-2">
                    <Label>Quality</Label>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <button
                            v-for="option in qualities"
                            :key="option"
                            type="button"
                            :class="[
                                'rounded-md border p-3 text-left',
                                form.quality === option ? 'border-primary bg-primary/5 ring-primary/30 ring-2' : 'bg-background hover:bg-muted',
                            ]"
                            @click="form.quality = option"
                        >
                            <div class="text-sm font-medium capitalize">{{ option }}</div>
                            <div class="text-muted-foreground mt-0.5 text-xs">{{ qualityHints[option] }}</div>
                        </button>
                    </div>
                    <InputError :message="form.errors.quality" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label>Reference images <span class="text-muted-foreground font-normal">(optional, max 4 — 765 image tokens each)</span></Label>
                        <Button v-if="form.reference_images.length < 4" type="button" variant="outline" size="sm" @click="addReference">
                            Add URL
                        </Button>
                    </div>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="flex items-center gap-2">
                        <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/style.png" />
                        <Button type="button" variant="ghost" size="sm" @click="removeReference(index)">✕</Button>
                    </div>
                    <InputError :message="form.errors['reference_images.0']" />
                </div>

                <InputError :message="form.errors.credits" />
            </form>

            <aside class="flex flex-col gap-4">
                <div class="rounded-xl border p-4">
                    <div class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Estimated price</div>
                    <div class="mt-1 text-2xl font-semibold">
                        <span v-if="estimate">${{ estimate.estimated }}</span>
                        <span v-else class="text-muted-foreground">…</span>
                    </div>
                    <div v-if="estimate" class="text-muted-foreground mt-1 text-xs">
                        usually ${{ estimate.min }} – ${{ estimate.max }} depending on reasoning length
                    </div>
                    <dl v-if="estimate" class="mt-4 space-y-1 text-xs">
                        <div class="flex justify-between"><dt class="text-muted-foreground">Input text</dt><dd>{{ estimate.tokens.input_text_tokens }} tok</dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Input images</dt><dd>{{ estimate.tokens.input_image_tokens }} tok</dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Output</dt><dd>{{ estimate.tokens.output_tokens }} tok</dd></div>
                        <div class="flex justify-between"><dt class="text-muted-foreground">Reasoning (est.)</dt><dd>{{ estimate.tokens.reasoning_tokens }} tok</dd></div>
                    </dl>
                    <p class="text-muted-foreground mt-4 text-xs leading-relaxed">
                        Estimate = expected token counts at the rates frozen when you submit. You're billed the
                        <em>actual</em> tokens once it finishes — never more than the max above, and nothing if it fails.
                    </p>
                </div>

                <div class="rounded-xl border p-4">
                    <div class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Your balance</div>
                    <div class="mt-1 text-xl font-semibold">${{ balance }}</div>
                    <p v-if="insufficient" class="text-destructive mt-2 text-xs">
                        Not enough for this generation's worst case —
                        <a href="/credits" class="underline">buy credits</a>.
                    </p>
                </div>

                <Button type="submit" :disabled="form.processing || !form.prompt.trim() || insufficient" class="w-full" @click="submit">
                    {{ form.processing ? 'Submitting…' : 'Generate' }}
                </Button>
            </aside>
        </div>
    </AppLayout>
</template>
