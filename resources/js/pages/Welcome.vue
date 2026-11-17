<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Pixelforge Studio" />

    <div class="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        <header class="mx-auto flex w-full max-w-5xl items-center justify-end gap-4 p-6 text-sm">
            <Link
                v-if="$page.props.auth.user"
                :href="route('dashboard')"
                class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]"
            >
                Dashboard
            </Link>
            <template v-else>
                <Link :href="route('login')" class="inline-block rounded-sm px-5 py-1.5 hover:underline">Log in</Link>
                <Link
                    :href="route('register')"
                    class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]"
                >
                    Register
                </Link>
            </template>
        </header>

        <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col items-center justify-center gap-6 px-6 pb-24 text-center">
            <h1 class="text-4xl font-semibold tracking-tight lg:text-5xl">Pixelforge Studio</h1>
            <p class="max-w-xl text-lg text-[#706f6c] dark:text-[#A1A09A]">
                Generate AI images with transparent, exact pricing — you pay the provider's token cost plus a flat 25%
                markup, and only when a generation succeeds.
            </p>
            <div class="flex gap-3">
                <Link
                    :href="$page.props.auth.user ? route('generations.create') : route('register')"
                    class="rounded-md bg-[#1b1b18] px-6 py-2.5 text-sm font-medium text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18] dark:hover:bg-white"
                >
                    Start generating
                </Link>
            </div>
            <ul class="mt-8 grid max-w-2xl gap-3 text-left text-sm text-[#706f6c] sm:grid-cols-3 dark:text-[#A1A09A]">
                <li class="rounded-lg border border-[#19140035] p-4 dark:border-[#3E3E3A]">
                    <strong class="text-[#1b1b18] dark:text-[#EDEDEC]">Exact money</strong><br />
                    Decimal-string billing to 9 places. Frozen per-generation rates — never hardcoded.
                </li>
                <li class="rounded-lg border border-[#19140035] p-4 dark:border-[#3E3E3A]">
                    <strong class="text-[#1b1b18] dark:text-[#EDEDEC]">Honest estimates</strong><br />
                    See the expected and worst-case price before you submit.
                </li>
                <li class="rounded-lg border border-[#19140035] p-4 dark:border-[#3E3E3A]">
                    <strong class="text-[#1b1b18] dark:text-[#EDEDEC]">No charge on failure</strong><br />
                    Failed generations cost nothing — ever.
                </li>
            </ul>
        </main>
    </div>
</template>
