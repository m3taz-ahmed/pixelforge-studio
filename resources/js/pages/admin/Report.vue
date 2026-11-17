<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Usage report', href: '/admin/usage-report' }];

interface DayRow {
    date: string;
    generations: number;
    provider_cost_local: string;
    provider_cost_reported: string | null;
    reconcile_delta: string | null;
    credit_spend: string;
    profit: string;
}

interface UserRow {
    user: string;
    credit_spend: string;
    provider_cost: string;
    profit: string;
}

const props = defineProps<{
    start: string;
    end: string;
    days: DayRow[];
    perUser: UserRow[];
    providerUsageUnavailable: boolean;
}>();

const start = ref(props.start);
const end = ref(props.end);

function applyRange(): void {
    router.get('/admin/usage-report', { start: start.value, end: end.value }, { preserveState: true });
}
</script>

<template>
    <Head title="Usage report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-6xl p-4">
            <Heading
                title="Usage report"
                description="Credit spend (user charge) vs provider cost. Local provider cost is reconciled against Pixelforge GET /v1/usage — the delta should be $0."
            />

            <div class="mb-4 flex flex-wrap items-end gap-3">
                <div class="grid gap-1">
                    <Label for="start">Start</Label>
                    <Input id="start" v-model="start" type="date" class="w-40" />
                </div>
                <div class="grid gap-1">
                    <Label for="end">End</Label>
                    <Input id="end" v-model="end" type="date" class="w-40" />
                </div>
                <Button @click="applyRange">Apply</Button>
                <p v-if="providerUsageUnavailable" class="text-amber-600 text-sm dark:text-amber-400">
                    Provider usage report unavailable — showing local records only.
                </p>
            </div>

            <section class="overflow-x-auto rounded-xl border">
                <h2 class="border-b px-4 py-3 text-sm font-medium">Per day</h2>
                <table class="w-full text-sm whitespace-nowrap">
                    <thead class="text-muted-foreground border-b text-left text-xs">
                        <tr>
                            <th class="px-4 py-2 font-medium">Date (UTC)</th>
                            <th class="px-4 py-2 text-right font-medium">Generations</th>
                            <th class="px-4 py-2 text-right font-medium">Provider cost (local)</th>
                            <th class="px-4 py-2 text-right font-medium">Provider cost (/v1/usage)</th>
                            <th class="px-4 py-2 text-right font-medium">Δ reconcile</th>
                            <th class="px-4 py-2 text-right font-medium">Credit spend</th>
                            <th class="px-4 py-2 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="day in days" :key="day.date" class="border-b last:border-0">
                            <td class="px-4 py-2 font-mono">{{ day.date }}</td>
                            <td class="px-4 py-2 text-right">{{ day.generations }}</td>
                            <td class="px-4 py-2 text-right font-mono">${{ day.provider_cost_local }}</td>
                            <td class="px-4 py-2 text-right font-mono">
                                <template v-if="day.provider_cost_reported !== null">${{ day.provider_cost_reported }}</template>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td
                                class="px-4 py-2 text-right font-mono"
                                :class="day.reconcile_delta === null ? 'text-muted-foreground' : day.reconcile_delta === '0.00' ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive'"
                            >
                                {{ day.reconcile_delta === null ? '—' : `$${day.reconcile_delta}` }}
                            </td>
                            <td class="px-4 py-2 text-right font-mono">${{ day.credit_spend }}</td>
                            <td class="px-4 py-2 text-right font-mono">${{ day.profit }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="mt-6 overflow-x-auto rounded-xl border">
                <h2 class="border-b px-4 py-3 text-sm font-medium">Per user</h2>
                <div v-if="perUser.length === 0" class="text-muted-foreground p-8 text-center text-sm">No charges in this range.</div>
                <table v-else class="w-full text-sm whitespace-nowrap">
                    <thead class="text-muted-foreground border-b text-left text-xs">
                        <tr>
                            <th class="px-4 py-2 font-medium">User</th>
                            <th class="px-4 py-2 text-right font-medium">Credit spend</th>
                            <th class="px-4 py-2 text-right font-medium">Provider cost</th>
                            <th class="px-4 py-2 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in perUser" :key="row.user" class="border-b last:border-0">
                            <td class="px-4 py-2">{{ row.user }}</td>
                            <td class="px-4 py-2 text-right font-mono">${{ row.credit_spend }}</td>
                            <td class="px-4 py-2 text-right font-mono">${{ row.provider_cost }}</td>
                            <td class="px-4 py-2 text-right font-mono">${{ row.profit }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
