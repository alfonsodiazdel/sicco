<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import { facturasServicio, urlArchivoFactura } from '@/services/facturas'
import { decimales, soles } from '@/services/presupuesto'
import { sumar } from '@/validaciones/factura'
import type { FacturaDetalle, FacturaFila } from '@/types/facturas'
import { mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'

const toast = useToast()
const router = useRouter()
const auth = useAuthStore()
const proyectoActual = useProyectoActualStore()

// El administrador consulta; registra Oficina técnica (CU11).
const puedeRegistrar = computed(() => auth.tieneRol('oficina_tecnica'))

const { proyecto, proyectoId } = storeToRefs(proyectoActual)
const facturas = ref<FacturaFila[]>([])
const cargando = ref(true)
const detalle = ref<FacturaDetalle | null>(null)
const verDetalle = ref(false)

// CU11 postcondición: el costo real del proyecto es la suma de las facturas, sin IGV.
const costoReal = computed(() => sumar(...facturas.value.map((f) => f.subtotal)))
const totalFacturado = computed(() => sumar(...facturas.value.map((f) => f.total)))
const volumenFacturado = computed(() => sumar(...facturas.value.map((f) => f.volumen_m3)))

const columnas = [
  { campo: 'numero', encabezado: 'N.° de factura' },
  { campo: 'fecha', encabezado: 'Fecha' },
  { campo: 'proveedor', encabezado: 'Proveedor', secundaria: true },
  { campo: 'guias_count', encabezado: 'Guías', numerica: true, secundaria: true },
  { campo: 'volumen_m3', encabezado: 'Volumen (m³)', numerica: true },
  { campo: 'subtotal', encabezado: 'Subtotal (S/)', numerica: true, secundaria: true },
  { campo: 'total', encabezado: 'Total (S/)', numerica: true },
]

const formatoFecha = (iso: string) => iso.split('-').reverse().join('/')

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

async function cargar() {
  const id = proyectoId.value
  if (!id) {
    cargando.value = false
    return
  }
  cargando.value = true
  try {
    const encontradas = await facturasServicio.listar(id)
    // Si se eligió otro proyecto mientras cargaba, esta respuesta ya no aplica.
    if (id === proyectoId.value) facturas.value = encontradas
  } catch (error) {
    if (id === proyectoId.value) avisarError(error)
  } finally {
    if (id === proyectoId.value) cargando.value = false
  }
}

async function abrirDetalle(factura: FacturaFila) {
  try {
    detalle.value = await facturasServicio.obtener(factura.id)
    verDetalle.value = true
  } catch (error) {
    avisarError(error)
  }
}

watch(
  () => proyectoActual.cargado && proyectoId.value,
  () => {
    if (!proyectoActual.cargado) return
    facturas.value = []
    cargar()
  },
  { immediate: true },
)

onMounted(async () => {
  try {
    await proyectoActual.cargar()
  } catch (error) {
    avisarError(error)
    cargando.value = false
  }
})
</script>

<template>
  <PaginaListado
    :titulo="proyecto ? `Facturas – ${proyecto.nombre}` : 'Facturas'"
    subtitulo="Facturas de concreto del proyecto, vinculadas a las guías que cobran"
  >
    <template #acciones>
      <Button
        v-if="puedeRegistrar"
        label="Nueva factura"
        icon="pi pi-plus"
        :disabled="!proyecto"
        @click="router.push({ name: 'nueva-factura' })"
      />
    </template>

    <template #herramientas>
      <dl class="grid w-full grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Resumen de facturas">
        <div>
          <dt class="text-sm text-surface-500">Costo real (sin IGV)</dt>
          <dd class="text-lg font-semibold text-primary-700" data-costo-real>
            {{ soles(costoReal) }}
          </dd>
        </div>
        <div>
          <dt class="text-sm text-surface-500">Total facturado</dt>
          <dd class="text-lg font-semibold">{{ soles(totalFacturado) }}</dd>
        </div>
        <div>
          <dt class="text-sm text-surface-500">Volumen facturado</dt>
          <dd class="text-lg font-semibold">{{ decimales(volumenFacturado) }} m³</dd>
        </div>
        <div>
          <dt class="text-sm text-surface-500">Facturas</dt>
          <dd class="text-lg font-semibold">{{ facturas.length }}</dd>
        </div>
      </dl>
    </template>

    <TablaCrud
      :filas="facturas"
      :columnas="columnas"
      clave="id"
      vacio="No hay facturas registradas en el proyecto"
      :cargando="cargando"
    >
      <template #col-numero="{ fila }">
        <span class="font-medium">{{ fila.numero }}</span>
        <Tag
          v-if="fila.diferencia_confirmada"
          value="Volumen distinto"
          severity="warn"
          class="ml-2"
          title="Se registró aunque el volumen facturado no coincidía con las guías"
        />
        <i
          v-if="fila.origen === 'ocr'"
          class="pi pi-sparkles ml-2 text-xs text-surface-400"
          title="Leída con OCR"
          aria-label="Leída con OCR"
        />
        <span class="block text-sm text-surface-500 sm:hidden">{{
          fila.proveedor.razon_social
        }}</span>
      </template>
      <template #col-fecha="{ fila }">{{ formatoFecha(fila.fecha) }}</template>
      <template #col-proveedor="{ fila }">{{ fila.proveedor.razon_social }}</template>
      <template #col-volumen_m3="{ fila }">{{ decimales(fila.volumen_m3) }}</template>
      <template #col-subtotal="{ fila }">{{ decimales(fila.subtotal) }}</template>
      <template #col-total="{ fila }">{{ decimales(fila.total) }}</template>
      <template #acciones="{ fila }">
        <Button
          as="a"
          :href="urlArchivoFactura(fila.id)"
          target="_blank"
          rel="noopener"
          icon="pi pi-paperclip"
          text
          rounded
          size="small"
          :aria-label="`Ver archivo de la factura ${fila.numero}`"
          :title="`Ver archivo de la factura ${fila.numero}`"
        />
        <Button
          icon="pi pi-eye"
          text
          rounded
          size="small"
          :aria-label="`Ver detalle de la factura ${fila.numero}`"
          :title="`Ver detalle de la factura ${fila.numero}`"
          @click="abrirDetalle(fila)"
        />
      </template>
    </TablaCrud>
  </PaginaListado>

  <Dialog
    v-model:visible="verDetalle"
    :header="detalle ? `Factura ${detalle.numero}` : 'Factura'"
    modal
    dismissable-mask
    :style="{ width: '48rem', maxWidth: 'calc(100vw - 2rem)' }"
  >
    <div v-if="detalle" class="space-y-4 text-sm" data-detalle>
      <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div>
          <dt class="text-surface-500">Proveedor</dt>
          <dd class="font-medium">{{ detalle.proveedor.razon_social }}</dd>
        </div>
        <div>
          <dt class="text-surface-500">Fecha</dt>
          <dd class="font-medium">{{ formatoFecha(detalle.fecha) }}</dd>
        </div>
        <div>
          <dt class="text-surface-500">Subtotal / IGV</dt>
          <dd class="font-medium">{{ soles(detalle.subtotal) }} / {{ soles(detalle.igv) }}</dd>
        </div>
        <div>
          <dt class="text-surface-500">Total</dt>
          <dd class="font-semibold">{{ soles(detalle.total) }}</dd>
        </div>
      </dl>

      <section>
        <h3 class="mb-1 font-semibold">Detalle</h3>
        <table class="w-full" aria-label="Detalle por resistencia">
          <thead>
            <tr class="border-b border-surface-200 text-left text-surface-600">
              <th class="py-1.5 pr-2 font-medium">Resistencia</th>
              <th class="px-2 py-1.5 text-right font-medium">m³</th>
              <th class="px-2 py-1.5 text-right font-medium">P. unit.</th>
              <th class="py-1.5 pl-2 text-right font-medium">Importe</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in detalle.lineas" :key="l.id" class="border-b border-surface-100">
              <td class="py-1.5 pr-2">{{ l.resistencia.nombre }}</td>
              <td class="px-2 py-1.5 text-right">{{ decimales(l.volumen_m3) }}</td>
              <td class="px-2 py-1.5 text-right">{{ decimales(l.precio_unitario) }}</td>
              <td class="py-1.5 pl-2 text-right">{{ decimales(l.importe) }}</td>
            </tr>
            <tr
              v-for="c in detalle.cargos"
              :key="`cargo-${c.id}`"
              class="border-b border-surface-100"
              data-cargo
            >
              <td class="py-1.5 pr-2" colspan="3">{{ c.descripcion }}</td>
              <td class="py-1.5 pl-2 text-right">{{ decimales(c.importe) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section>
        <h3 class="mb-1 font-semibold">Guías vinculadas</h3>
        <table class="w-full" aria-label="Guías vinculadas">
          <thead>
            <tr class="border-b border-surface-200 text-left text-surface-600">
              <th class="py-1.5 pr-2 font-medium">N.° guía</th>
              <th class="px-2 py-1.5 font-medium">Fecha</th>
              <th class="hidden px-2 py-1.5 font-medium sm:table-cell">Resistencia</th>
              <th class="px-2 py-1.5 text-right font-medium">m³</th>
              <th class="py-1.5 pl-2 text-right font-medium">Importe</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="g in detalle.guias" :key="g.id" class="border-b border-surface-100">
              <td class="py-1.5 pr-2 font-medium">{{ g.numero }}</td>
              <td class="px-2 py-1.5">{{ formatoFecha(g.fecha) }}</td>
              <td class="hidden px-2 py-1.5 sm:table-cell">{{ g.resistencia.nombre }}</td>
              <td class="px-2 py-1.5 text-right">{{ decimales(g.volumen_m3) }}</td>
              <td class="py-1.5 pl-2 text-right">
                {{ g.importe !== null ? decimales(g.importe) : '—' }}
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </div>
    <template #footer>
      <a
        v-if="detalle"
        :href="urlArchivoFactura(detalle.id)"
        target="_blank"
        rel="noopener"
        class="text-sm font-medium text-primary-700 underline"
        >Abrir archivo de la factura</a
      >
    </template>
  </Dialog>
</template>
