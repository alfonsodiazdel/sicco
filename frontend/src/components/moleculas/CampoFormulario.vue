<script setup lang="ts">
import { computed } from 'vue'

const {
  para,
  error,
  ancho = 'completo',
} = defineProps<{
  etiqueta: string
  para: string
  error?: string
  obligatorio?: boolean
  ancho?: 'medio' | 'completo'
}>()

// Se entrega al slot para que el control lo use en aria-describedby.
const idError = computed(() => (error ? `${para}-error` : undefined))
</script>

<template>
  <div class="space-y-1.5" :class="{ 'sm:col-span-2': ancho === 'completo' }">
    <label :for="para" class="block text-sm font-medium text-surface-700"
      >{{ etiqueta }}{{ obligatorio ? ' *' : '' }}</label
    >
    <slot :id-error="idError" />
    <small v-if="error" :id="idError" class="text-red-600" aria-live="polite">{{ error }}</small>
  </div>
</template>
