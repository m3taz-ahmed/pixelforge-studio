<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/history' }];

interface GenerationRow {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    status: 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';
    estimated_cost: string | null;
    user_charge: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_message: string | null;
    created_at: string;
}

const props = defineProps<{
    generations: GenerationRow[];
    balance: string;
}>();

const hasOpen = computed(() => props.generations.some((g) => ['pending', 'queued', 'processing'].includes(g.status)));

const { start, stop } = usePoll(2500, { only: ['generations'] }, { autoStart: false });
watch(hasOpen, (open) => (open ? start() : stop()), { immediate: true });

const statusStyle: Record<GenerationRow['status'], string> = {
    pending: 'bg-muted text-muted-foreground',
    queued: 'bg-blue-500/15 text-blue-600 dark:text-blue-400',
    processing: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    succeeded: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    failed: 'bg-destructive/15 text-destructive',
};

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl p-4">
            <div class="mb-6 flex items-start justify-between gap-4">
                <Heading title="Generation history" description="Status updates live. You're billed actual usage × frozen rates + 25%, only on success." />
                <div class="rounded-lg border px-3 py-2 text-sm whitespace-nowrap">Balance <strong>${{ balance }}</strong></div>
            </div>

            <div v-if="generations.length === 0" class="rounded-xl border border-dashed p-12 text-center">
                <p class="text-muted-foreground mb-4">No generations yet.</p>
                <Button as-child><a href="/generate">Generate your first image</a></Button>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article v-for="gen in generations" :key="gen.id" class="flex flex-col overflow-hidden rounded-xl border">
                    <div class="bg-muted relative aspect-square">
                        <img
                            v-if="gen.status === 'succeeded' && gen.image_url"
                            :src="gen.image_url"
                            :alt="gen.prompt"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <div v-else class="text-muted-foreground flex h-full flex-col items-center justify-center gap-2 p-4 text-center text-sm">
                            <span v-if="gen.status === 'failed'">Generation failed</span>
                            <span v-else class="animate-pulse capitalize">{{ gen.status }}…</span>
                        </div>
                        <span :class="['absolute top-2 right-2 rounded-md px-2 py-0.5 text-xs font-medium capitalize', statusStyle[gen.status]]">
                            {{ gen.status }}
                        </span>
                    </div>

                    <div class="flex flex-1 flex-col gap-2 p-3">
                        <p class="line-clamp-2 text-sm" :title="gen.prompt">{{ gen.prompt }}</p>
                        <p class="text-muted-foreground font-mono text-xs">{{ gen.size }} · {{ gen.quality }}</p>

                        <dl class="mt-auto space-y-1 text-xs">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Estimated</dt>
                                <dd>${{ gen.estimated_cost ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Charged</dt>
                                <dd v-if="gen.status === 'succeeded' && gen.user_charge" class="font-medium">${{ gen.user_charge }}</dd>
                                <dd v-else-if="gen.status === 'failed'" class="text-emerald-600 dark:text-emerald-400">$0 — not charged</dd>
                                <dd v-else class="text-muted-foreground">pending</dd>
                            </div>
                        </dl>

                        <p v-if="gen.status === 'failed' && gen.error_message" class="text-destructive text-xs">
                            {{ gen.error_message }}
                        </p>
                        <p class="text-muted-foreground text-xs">{{ formatDate(gen.created_at) }}</p>
                    </div>
                </article>
            </div>
        </div>
    </AppLayout>
</template>
