<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Eye, EyeOff, LogOut, Settings } from '@lucide/vue';
import { computed } from 'vue';
import VisibilityPreferenceController from '@/actions/App/Http/Controllers/VisibilityPreferenceController';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

defineProps<Props>();

const page = usePage();
const authUser = computed(() => page.props.auth.user as User & {
    role?: string;
    see_everyone?: boolean;
});

const canToggleVisibility = computed(() => {
    const role = authUser.value?.role;
    return role === 'salesman' || role === 'project_manager' || role === 'admin';
});

const handleLogout = () => {
    router.flushAll();
};

function toggleVisibility(): void {
    router.patch(VisibilityPreferenceController.update.url(), {
        see_everyone: !authUser.value?.see_everyone,
    });
}
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Settings
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem
            v-if="canToggleVisibility && authUser?.role !== 'admin'"
            class="cursor-pointer"
            @click="toggleVisibility"
        >
            <EyeOff v-if="authUser?.see_everyone" class="mr-2 h-4 w-4" />
            <Eye v-else class="mr-2 h-4 w-4" />
            {{ authUser?.see_everyone ? 'Show only mine' : 'Show everyone' }}
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </Link>
    </DropdownMenuItem>
</template>
