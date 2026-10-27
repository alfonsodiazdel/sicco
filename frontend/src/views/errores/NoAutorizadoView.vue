<script setup lang="ts">
import Button from 'primevue/button'
</script>

<template>
  <div class="mx-auto mt-16 max-w-md text-center">
    <i class="pi pi-lock text-4xl text-surface-300" aria-hidden="true" />
    <h2 class="mt-4 text-lg font-semibold text-surface-900">No tiene acceso a esta opción</h2>
    <p class="mt-1 text-surface-500">Su rol no permite ingresar a esta sección.</p>
    <RouterLink to="/" class="mt-6 inline-block">
      <Button label="Ir al inicio" severity="secondary" outlined />
    </RouterLink>
  </div>
</template>
