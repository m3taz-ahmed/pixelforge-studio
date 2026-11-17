<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: '/credits' }];

interface Transaction {
    id: string;
    type: 'topup' | 'hold' | 'release' | 'charge';
    amount: string;
    balance_after: string;
    memo: string | null;
    created_at: string | null;
}

defineProps<{
    balance: string;
    transactions: Transaction[];
}>();

const form = useForm<{ amount: string }>({ amount: '10' });
const presets = ['5', '10', '20', '50'];

const typeLabel: Record<Transaction['type'], string> = {
    topup: 'Top-up',
    hold: 'Hold',
    release: 'Hold released',
    charge: 'Charge',
};

const isDebit = (type: Transaction['type']) => type === 'charge' || type === 'hold';

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto grid w-full max-w-4xl gap-6 p-4 md:grid-cols-[280px_1fr]">
            <section class="flex flex-col gap-4">
                <Heading title="Credits" description="Demo balance — top-ups are fake, no payment involved." />

                <div class="rounded-xl border p-4">
                    <div class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Balance</div>
                    <div class="mt-1 text-3xl font-semibold">${{ balance }}</div>
                </div>

                <form class="rounded-xl border p-4" @submit.prevent="form.post('/credits')">
                    <Label for="amount">Buy credits</Label>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button
                            v-for="preset in presets"
                            :key="preset"
                            type="button"
                            :class="[
                                'rounded-md border px-3 py-1 text-sm',
                                form.amount === preset ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted',
                            ]"
                            @click="form.amount = preset"
                        >
                            ${{ preset }}
                        </button>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <Input id="amount" v-model="form.amount" type="number" min="1" max="500" step="0.01" />
                        <Button type="submit" :disabled="form.processing">Top up</Button>
                    </div>
                    <InputError :message="form.errors.amount" />
                </form>
            </section>

            <section class="rounded-xl border">
                <h2 class="border-b px-4 py-3 text-sm font-medium">Transactions</h2>
                <div v-if="transactions.length === 0" class="text-muted-foreground p-8 text-center text-sm">No transactions yet.</div>
                <table v-else class="w-full text-sm">
                    <thead class="text-muted-foreground border-b text-left text-xs">
                        <tr>
                            <th class="px-4 py-2 font-medium">When</th>
                            <th class="px-4 py-2 font-medium">Type</th>
                            <th class="px-4 py-2 font-medium">Memo</th>
                            <th class="px-4 py-2 text-right font-medium">Amount</th>
                            <th class="px-4 py-2 text-right font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tx in transactions" :key="tx.id" class="border-b last:border-0">
                            <td class="px-4 py-2 whitespace-nowrap">{{ formatDate(tx.created_at) }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ typeLabel[tx.type] ?? tx.type }}</td>
                            <td class="text-muted-foreground max-w-40 truncate px-4 py-2">{{ tx.memo ?? '—' }}</td>
                            <td class="px-4 py-2 text-right font-mono tabular-nums" :class="isDebit(tx.type) ? 'text-destructive' : 'text-emerald-600 dark:text-emerald-400'">
                                {{ isDebit(tx.type) ? '−' : '+' }}${{ tx.amount.replace('-', '') }}
                            </td>
                            <td class="text-muted-foreground px-4 py-2 text-right font-mono">${{ tx.balance_after }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
