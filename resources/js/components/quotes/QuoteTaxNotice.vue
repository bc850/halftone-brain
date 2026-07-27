<script setup lang="ts">
defineProps<{
    message?: string | null;
}>();
</script>

<template>
    <div
        v-if="message"
        class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
    >
        {{ message }}
    </div>
</template>
