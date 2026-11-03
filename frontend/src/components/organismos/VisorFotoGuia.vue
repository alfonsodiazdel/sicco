<script setup lang="ts">
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import ProgressSpinner from 'primevue/progressspinner'
import { urlFotoGuia } from '@/services/guias'

/**
 * Botón que abre la foto de una guía registrada en un diálogo. La imagen se pide recién al abrirlo,
 * para que una lista de guías no descargue todas las fotos.
 */
const { guiaId, numero, etiqueta } = defineProps<{
  guiaId: number
  numero: string
  /** Texto del botón; sin él, solo el ícono. */
  etiqueta?: string
}>()

const visible = ref(false)
const estado = ref<'cargando' | 'lista' | 'error'>('cargando')
const url = computed(() => urlFotoGuia(guiaId))

function abrir() {
  estado.value = 'cargando'
  visible.value = true
}
</script>

<template>
  <Button
    icon="pi pi-image"
    :label="etiqueta"
    :text="!etiqueta"
    :outlined="!!etiqueta"
    :rounded="!etiqueta"
    size="small"
    :aria-label="`Ver foto de la guía ${numero}`"
    :title="`Ver foto de la guía ${numero}`"
    @click="abrir"
  />

  <Dialog
    v-model:visible="visible"
    :header="`Guía ${numero}`"
    modal
    dismissable-mask
    :style="{ width: '48rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <div class="relative flex min-h-48 items-center justify-center">
      <ProgressSpinner
        v-if="estado === 'cargando'"
        class="absolute"
        style="width: 2.5rem; height: 2.5rem"
        aria-label="Cargando la foto"
      />
      <p v-if="estado === 'error'" class="text-surface-500" role="alert">
        No se pudo cargar la foto de la guía.
      </p>
      <img
        v-else
        :src="url"
        :alt="`Foto de la guía ${numero}`"
        class="max-h-[75vh] w-full rounded-md object-contain"
        :class="{ invisible: estado === 'cargando' }"
        @load="estado = 'lista'"
        @error="estado = 'error'"
      />
    </div>
    <template #footer>
      <a
        v-if="estado === 'lista'"
        :href="url"
        target="_blank"
        rel="noopener"
        class="text-sm font-medium text-primary-700 underline"
        >Abrir en tamaño completo</a
      >
    </template>
  </Dialog>
</template>
