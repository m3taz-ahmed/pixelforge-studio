<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

interface RecentGeneration {
    id: string;
    prompt: string;
    status: string;
    image_url: string | null;
    created_at: string;
}

const props = defineProps<{
    balance: string;
    counts: Record<string, number>;
    recent: RecentGeneration[];
}>();

const hasOpen = computed(() => props.recent.some((g) => ['pending', 'queued', 'processing'].includes(g.status)));

const { start, stop } = usePoll(2500, { only: ['recent', 'counts'] }, { autoStart: false });
watch(hasOpen, (open) => (open ? start() : stop()), { immediate: true });

const total = computed(() => Object.values(props.counts).reduce((sum, n) => sum + n, 0));
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-5xl p-4">
            <Heading title="AI Image Studio" description="Generate images through Pixelforge. You pay provider cost + 25% — only on success." />

            <div class="mt-2 grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border p-4">
                    <div class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Balance</div>
                    <div class="mt-1 text-2xl font-semibold">${{ balance }}</div>
                    <Link href="/credits" class="text-primary text-xs underline">Top up</Link>
                </div>
                <div class="rounded-xl border p-4">
                    <div class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Generations</div>
                    <div class="mt-1 text-2xl font-semibold">{{ total }}</div>
                    <div class="text-muted-foreground text-xs">
                        {{ counts.succeeded ?? 0 }} succeeded · {{ counts.failed ?? 0 }} failed
                    </div>
                </div>
                <div class="flex items-center justify-center rounded-xl border border-dashed p-4">
                    <Button as-child size="lg"><Link href="/generate">New generation</Link></Button>
                </div>
            </div>

            <section v-if="recent.length" class="mt-8">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-medium">Recent</h2>
                    <Link href="/history" class="text-primary text-xs underline">View all</Link>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Link
                        v-for="gen in recent"
                        :key="gen.id"
                        href="/history"
                        class="group overflow-hidden rounded-xl border transition hover:shadow-md"
                    >
                        <div class="bg-muted aspect-square">
                            <img v-if="gen.status === 'succeeded' && gen.image_url" :src="gen.image_url" :alt="gen.prompt" class="h-full w-full object-cover" loading="lazy" />
                            <div v-else class="text-muted-foreground flex h-full items-center justify-center text-xs capitalize">{{ gen.status }}</div>
                        </div>
                        <p class="text-muted-foreground line-clamp-1 p-2 text-xs">{{ gen.prompt }}</p>
                    </Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
