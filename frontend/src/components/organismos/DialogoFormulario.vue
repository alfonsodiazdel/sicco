<script setup lang="ts">
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'

const visible = defineModel<boolean>('visible', { required: true })

defineProps<{ titulo: string; guardando?: boolean }>()

const emit = defineEmits<{ guardar: []; cancelar: [] }>()

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
    :style="{ width: '32rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <form novalidate @submit.prevent="emit('guardar')">
      <p class="mb-4 text-sm text-surface-500">Los campos con * son requeridos.</p>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <slot />
      </div>
      <div class="mt-6 flex justify-end gap-2">
        <Button type="button" label="Cancelar" severity="secondary" text @click="cancelar" />
        <Button type="submit" label="Guardar" :loading="guardando" />
      </div>
    </form>
  </Dialog>
</template>
