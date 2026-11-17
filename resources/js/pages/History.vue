<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Clock, ImageOff, Loader2, Sparkles, Wallet } from 'lucide-vue-next';
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

const { start, stop } = usePoll(2500, { only: ['generations', 'balance'] }, { autoStart: false });
watch(hasOpen, (open) => (open ? start() : stop()), { immediate: true });

const statusStyle: Record<GenerationRow['status'], string> = {
    pending: 'bg-muted/90 text-muted-foreground',
    queued: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    processing: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    succeeded: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-destructive/15 text-destructive',
};

const isOpen = (status: GenerationRow['status']) => ['pending', 'queued', 'processing'].includes(status);

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6">
            <header class="mb-8 flex flex-wrap items-end justify-between gap-x-8 gap-y-3 border-b pb-5">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight">Generation history</h1>
                    <p class="text-muted-foreground mt-1.5 max-w-xl text-sm">
                        Status updates live. You're billed actual usage × frozen rates + 25% — only on success.
                    </p>
                </div>
                <Link
                    href="/credits"
                    class="bg-muted/60 hover:bg-muted flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm transition-colors"
                    title="Your credit balance — manage credits"
                >
                    <Wallet class="text-muted-foreground size-4" />
                    <span class="text-muted-foreground">Balance</span>
                    <span class="font-semibold tabular-nums">${{ balance }}</span>
                </Link>
            </header>

            <div v-if="generations.length === 0" class="rounded-xl border border-dashed p-16 text-center">
                <Sparkles class="text-muted-foreground/50 mx-auto mb-3 size-8" />
                <p class="text-muted-foreground mb-4">No generations yet.</p>
                <Button as-child><Link href="/generate">Generate your first image</Link></Button>
            </div>

            <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="gen in generations"
                    :key="gen.id"
                    class="group bg-card flex flex-col overflow-hidden rounded-xl border shadow-sm transition-shadow hover:shadow-md"
                >
                    <div class="bg-muted relative aspect-[4/3] overflow-hidden">
                        <img
                            v-if="gen.status === 'succeeded' && gen.image_url"
                            :src="gen.image_url"
                            :alt="gen.prompt"
                            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                            loading="lazy"
                        />
                        <div
                            v-else-if="gen.status === 'failed'"
                            class="bg-destructive/5 flex h-full flex-col items-center justify-center gap-2"
                        >
                            <ImageOff class="text-destructive/50 size-8" />
                            <span class="text-destructive/70 text-xs font-medium">Generation failed</span>
                        </div>
                        <div v-else class="flex h-full flex-col items-center justify-center gap-2.5">
                            <Loader2 v-if="gen.status === 'processing'" class="text-muted-foreground size-7 animate-spin" />
                            <Clock v-else class="text-muted-foreground size-7" />
                            <span class="text-muted-foreground text-xs capitalize">{{ gen.status }}…</span>
                        </div>
                        <span
                            :class="[
                                'absolute top-2.5 right-2.5 rounded-md px-2 py-0.5 text-xs font-medium capitalize backdrop-blur-sm',
                                statusStyle[gen.status],
                            ]"
                        >
                            <span v-if="isOpen(gen.status)" class="mr-1 inline-block size-1.5 animate-pulse rounded-full bg-current align-middle" />
                            {{ gen.status }}
                        </span>
                    </div>

                    <div class="flex flex-1 flex-col gap-2.5 p-4">
                        <p class="line-clamp-2 min-h-10 text-sm font-medium" :title="gen.prompt">{{ gen.prompt }}</p>
                        <p class="text-muted-foreground font-mono text-xs">{{ gen.size }} · {{ gen.quality }}</p>

                        <dl class="mt-auto space-y-1.5 pt-1 text-xs">
                            <div class="flex items-baseline justify-between">
                                <dt class="text-muted-foreground">Estimated</dt>
                                <dd class="tabular-nums">${{ gen.estimated_cost ?? '—' }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between border-t pt-1.5">
                                <dt class="text-muted-foreground">Charged</dt>
                                <dd v-if="gen.status === 'succeeded' && gen.user_charge" class="font-semibold tabular-nums">
                                    ${{ gen.user_charge }}
                                </dd>
                                <dd v-else-if="gen.status === 'failed'" class="font-medium text-emerald-600 dark:text-emerald-400">
                                    $0 — not charged
                                </dd>
                                <dd v-else class="text-muted-foreground">pending</dd>
                            </div>
                        </dl>

                        <p v-if="gen.status === 'failed' && gen.error_message" class="text-destructive/90 text-xs leading-relaxed">
                            {{ gen.error_message }}
                        </p>
                        <p class="text-muted-foreground text-xs">{{ formatDate(gen.created_at) }}</p>
                    </div>
                </article>
            </div>
        </div>
    </AppLayout>
</template>
