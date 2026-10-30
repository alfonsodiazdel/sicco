<script setup lang="ts">
import EncabezadoPagina from '@/components/organismos/EncabezadoPagina.vue'

defineProps<{ titulo: string; subtitulo?: string }>()
</script>

<template>
  <section class="space-y-4">
    <EncabezadoPagina :titulo="titulo" :subtitulo="subtitulo">
      <template v-if="$slots.acciones" #acciones>
        <slot name="acciones" />
      </template>
    </EncabezadoPagina>

    <div class="overflow-hidden rounded-lg border border-surface-200 bg-white">
      <div
        v-if="$slots.herramientas"
        class="flex flex-col gap-3 border-b border-surface-200 p-3 sm:flex-row"
      >
        <slot name="herramientas" />
      </div>
      <slot />
    </div>

    <slot name="dialogos" />
  </section>
</template>
