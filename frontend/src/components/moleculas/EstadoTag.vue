<script setup lang="ts">
import Tag from 'primevue/tag'

const { inactivo = 'Inactivo' } = defineProps<{ activo: boolean; inactivo?: string }>()
</script>

<template>
  <Tag :value="activo ? 'Activo' : inactivo" :severity="activo ? 'success' : 'secondary'" />
</template>
