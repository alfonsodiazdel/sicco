<script setup lang="ts">
import Tag from 'primevue/tag'

defineProps<{ activo: boolean }>()
</script>

<template>
  <Tag :value="activo ? 'Activo' : 'Inactivo'" :severity="activo ? 'success' : 'secondary'" />
</template>
