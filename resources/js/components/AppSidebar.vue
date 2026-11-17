<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, BookOpen, Coins, Folder, History, LayoutGrid, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage();
const isAdmin = computed(() => Boolean(page.props.auth?.user?.is_admin));

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
        { title: 'Generate', href: '/generate', icon: Sparkles },
        { title: 'History', href: '/history', icon: History },
        { title: 'Credits', href: '/credits', icon: Coins },
    ];

    if (isAdmin.value) {
        items.push({ title: 'Usage report', href: '/admin/usage-report', icon: BarChart3 });
    }

    return items;
});

const footerNavItems: NavItem[] = [
    {
        title: 'Pixelforge Docs',
        href: 'https://pixelforge.promptedit.dev/docs',
        icon: BookOpen,
    },
    {
        title: 'Source Code',
        href: 'https://github.com/m3taz-ahmed/pixelforge-studio',
        icon: Folder,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
