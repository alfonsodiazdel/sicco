<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import { decimales, soles } from '@/services/presupuesto'
import { importe, sugerirGuias, sumar, type FilaLinea } from '@/validaciones/factura'
import type { GuiaFila } from '@/types/guias'

/**
 * CU12 Vincular factura con guías de remisión: lista las guías del proveedor y proyecto aún no
 * facturadas, el usuario marca las que cobra la factura y el sistema suma su volumen e importe.
 */
const {
  guias,
  seleccion,
  precios,
  lineas = [],
  numeroFactura = '',
} = defineProps<{
  guias: GuiaFila[]
  /** Las guías ya vinculadas, para editar la selección. */
  seleccion: number[]
  /** Precio unitario de la factura por resistencia: da el importe de cada guía. */
  precios: Map<number, number>
  /** El concreto de la factura: con él se proponen las guías que completan su volumen. */
  lineas?: FilaLinea[]
  numeroFactura?: string
  cargando?: boolean
}>()

const visible = defineModel<boolean>('visible', { required: true })
const emit = defineEmits<{ confirmar: [ids: number[]] }>()

const marcadas = ref<number[]>([])
watch(visible, (abierto) => {
  if (abierto) marcadas.value = [...seleccion]
})

const importeDe = (g: GuiaFila) => {
  const precio = precios.get(g.resistencia.id)
  return precio ? importe(g.volumen_m3, precio) : null
}

// CU12 paso 3: suma del volumen y del importe de las guías marcadas.
const elegidas = computed(() => guias.filter((g) => marcadas.value.includes(g.id)))
const volumen = computed(() => sumar(...elegidas.value.map((g) => g.volumen_m3)))
const total = computed(() => sumar(...elegidas.value.map((g) => importeDe(g) ?? 0)))
const sinPrecio = computed(() => elegidas.value.some((g) => importeDe(g) === null))

const todas = computed({
  get: () => guias.length > 0 && marcadas.value.length === guias.length,
  set: (valor: boolean) => (marcadas.value = valor ? guias.map((g) => g.id) : []),
})

const formatoFecha = (iso: string) => iso.split('-').reverse().join('/')

/** Marca las pendientes que completan el volumen de cada resistencia, de la más antigua. */
const sugeridas = computed(() => sugerirGuias(guias, lineas))
function proponer() {
  marcadas.value = sugeridas.value
}

function confirmar() {
  // En el orden de la lista: de la más antigua a la más reciente.
  emit(
    'confirmar',
    guias.filter((g) => marcadas.value.includes(g.id)).map((g) => g.id),
  )
  visible.value = false
}
</script>

<template>
  <Dialog
    v-model:visible="visible"
    :header="numeroFactura ? `Vincular guías a la factura ${numeroFactura}` : 'Vincular guías'"
    modal
    :style="{ width: '52rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <p v-if="cargando" class="py-6 text-center text-surface-500">Cargando guías…</p>

    <!-- CU12 flujo alternativo 1. -->
    <Message v-else-if="!guias.length" severity="info" icon="pi pi-info-circle" data-sin-pendientes>
      No hay guías pendientes del proveedor
    </Message>

    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm" aria-label="Guías por facturar">
        <thead>
          <tr class="border-b border-surface-200 text-left text-surface-600">
            <th class="w-10 px-2 py-2">
              <Checkbox v-model="todas" binary input-id="todas" aria-label="Marcar todas" />
            </th>
            <th class="px-2 py-2 font-medium">N.° guía</th>
            <th class="px-2 py-2 font-medium">Fecha</th>
            <th class="hidden px-2 py-2 font-medium sm:table-cell">Resistencia</th>
            <th class="px-2 py-2 text-right font-medium">Volumen m³</th>
            <th class="px-2 py-2 text-right font-medium">Importe S/</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="g in guias"
            :key="g.id"
            class="border-b border-surface-100"
            :class="{ 'bg-primary-50': marcadas.includes(g.id) }"
            data-guia
          >
            <td class="px-2 py-2">
              <Checkbox
                v-model="marcadas"
                :value="g.id"
                :input-id="`guia-${g.id}`"
                :aria-label="`Marcar guía ${g.numero}`"
              />
            </td>
            <td class="px-2 py-2">
              <label :for="`guia-${g.id}`" class="cursor-pointer font-medium">{{ g.numero }}</label>
            </td>
            <td class="px-2 py-2">{{ formatoFecha(g.fecha) }}</td>
            <td class="hidden px-2 py-2 sm:table-cell">{{ g.resistencia.nombre }}</td>
            <td class="px-2 py-2 text-right">{{ decimales(g.volumen_m3) }}</td>
            <td class="px-2 py-2 text-right whitespace-nowrap">
              <template v-if="importeDe(g) !== null">{{ decimales(importeDe(g)!) }}</template>
              <span v-else class="text-amber-700" title="La factura no tiene esta resistencia"
                >—</span
              >
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="sinPrecio" class="mt-2 text-sm text-amber-700">
        <i class="pi pi-exclamation-triangle text-xs" aria-hidden="true" />
        Hay guías marcadas con una resistencia que no está en el detalle de la factura.
      </p>
    </div>

    <template #footer>
      <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="font-medium" data-seleccionado>
          Seleccionado: {{ decimales(volumen) }} m³ – {{ soles(total) }}
        </p>
        <div class="flex gap-2">
          <Button
            v-if="sugeridas.length"
            label="Proponer por volumen"
            icon="pi pi-sparkles"
            severity="secondary"
            text
            title="Marca las guías pendientes que completan el volumen facturado, de la más antigua"
            data-proponer
            @click="proponer"
          />
          <Button label="Cancelar" severity="secondary" text @click="visible = false" />
          <Button
            label="Confirmar"
            icon="pi pi-check"
            :disabled="cargando || !guias.length"
            @click="confirmar"
          />
        </div>
      </div>
    </template>
  </Dialog>
</template>
