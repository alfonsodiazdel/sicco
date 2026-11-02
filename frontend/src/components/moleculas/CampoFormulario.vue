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
  /** Aviso sin bloquear el envío, p. ej. un dato leído por OCR con baja confianza. */
  aviso?: string
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
    <small v-else-if="aviso" class="flex items-center gap-1 text-amber-700" data-aviso>
      <i class="pi pi-exclamation-triangle text-xs" aria-hidden="true" />{{ aviso }}
    </small>
  </div>
</template>
