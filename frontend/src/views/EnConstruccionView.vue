<script setup lang="ts">
import { useRoute } from 'vue-router'

const route = useRoute()
</script>

<template>
  <div class="mx-auto mt-16 max-w-md text-center">
    <i class="pi pi-wrench text-4xl text-surface-300" aria-hidden="true" />
    <h2 class="mt-4 text-lg font-semibold text-surface-900">{{ route.meta.titulo }}</h2>
    <p class="mt-1 text-surface-500">Este módulo todavía no está implementado.</p>
  </div>
</template>
