<script setup lang="ts">
import { useId } from 'vue'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'

const visible = defineModel<boolean>('visible', { required: true })

const { ancho = '32rem' } = defineProps<{ titulo: string; guardando?: boolean; ancho?: string }>()

const emit = defineEmits<{ guardar: []; cancelar: [] }>()

const idFormulario = useId()

function cancelar() {
  visible.value = false
  emit('cancelar')
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    :header="titulo"
    modal
    :style="{ width: ancho, maxWidth: 'calc(100vw - 2rem)' }"
  >
    <form :id="idFormulario" novalidate @submit.prevent="emit('guardar')">
      <p class="mb-4 text-sm text-surface-500">Los campos con * son requeridos.</p>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <slot />
      </div>
    </form>
    <template #footer>
      <Button type="button" label="Cancelar" severity="secondary" text @click="cancelar" />
      <Button type="submit" :form="idFormulario" label="Guardar" :loading="guardando" />
    </template>
  </Dialog>
</template>
