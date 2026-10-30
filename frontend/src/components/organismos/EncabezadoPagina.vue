<script setup lang="ts">
defineProps<{ titulo: string; subtitulo?: string }>()
</script>

<template>
  <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div>
      <h2 class="text-xl font-semibold text-surface-900">{{ titulo }}</h2>
      <p v-if="subtitulo" class="mt-0.5 text-sm text-surface-500">{{ subtitulo }}</p>
    </div>
    <div v-if="$slots.acciones" class="flex shrink-0 gap-2">
      <slot name="acciones" />
    </div>
  </div>
</template>
