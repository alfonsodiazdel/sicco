<script setup lang="ts">
import Button from 'primevue/button'
</script>

<template>
  <div class="mx-auto mt-16 max-w-md text-center">
    <p class="text-5xl font-semibold text-surface-300">404</p>
    <h2 class="mt-4 text-lg font-semibold text-surface-900">Página no encontrada</h2>
    <RouterLink to="/" class="mt-6 inline-block">
      <Button label="Ir al inicio" severity="secondary" outlined />
    </RouterLink>
  </div>
</template>
