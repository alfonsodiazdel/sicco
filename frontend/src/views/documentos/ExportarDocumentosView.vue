<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import FiltrosDocumentos from '@/components/organismos/FiltrosDocumentos.vue'
import { documentosServicio } from '@/services/documentos'
import { facturasServicio } from '@/services/facturas'
import { decimales, soles } from '@/services/presupuesto'
import { aIso } from '@/validaciones/proyecto'
import { mensajeDeError } from '@/api/http'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import type {
  FacturaDocumento,
  FiltroDocumentos,
  GuiaDocumento,
  PedidoZip,
  TipoDocumento,
} from '@/types/documentos'
import type { Proveedor } from '@/types/proveedor'

type Documento = GuiaDocumento | FacturaDocumento

const toast = useToast()
const proyectoActual = useProyectoActualStore()
const { proyecto, proyectoId } = storeToRefs(proyectoActual)

const tipo = ref<TipoDocumento>('guias')
const desde = ref<Date | null>(null)
const hasta = ref<Date | null>(null)
const proveedorId = ref<number | null>(null)
const estado = ref<GuiaDocumento['estado_asignacion'] | null>(null)
const proveedores = ref<Proveedor[]>([])
const filas = ref<Documento[]>([])
const seleccion = ref<Documento[]>([])
const cargando = ref(true)
const descargando = ref(false)
// Lo filtrado se descarga con los filtros de la última búsqueda, que son los de la tabla.
let buscado: FiltroDocumentos = { desde: null, hasta: null, proveedor_id: null }

const estados: { valor: GuiaDocumento['estado_asignacion']; etiqueta: string }[] = [
  { valor: 'sin_sector', etiqueta: 'Sin sector' },
  { valor: 'parcial', etiqueta: 'Parcial' },
  { valor: 'asignada', etiqueta: 'Asignada' },
]

const esGuias = computed(() => tipo.value === 'guias')
const rangoInvalido = computed(() => !!desde.value && !!hasta.value && hasta.value < desde.value)
const columnas = computed(() => [
  { campo: 'numero', encabezado: esGuias.value ? 'N° de guía' : 'N° de factura' },
  { campo: 'fecha', encabezado: 'Fecha' },
  { campo: 'proveedor', encabezado: 'Proveedor', secundaria: true },
  { campo: 'volumen_m3', encabezado: 'Volumen (m³)', numerica: true },
  esGuias.value
    ? { campo: 'estado_asignacion', encabezado: 'Estado', secundaria: true }
    : { campo: 'total', encabezado: 'Total S/', numerica: true },
  { campo: 'archivo', encabezado: esGuias.value ? 'Foto' : 'Archivo' },
])

const formatoFecha = (iso: string) => iso.split('-').reverse().join('/')
const tieneArchivo = (d: Documento) => ('tiene_foto' in d ? d.tiene_foto : d.tiene_archivo)
const etiquetaEstado = (d: Documento) =>
  'estado_asignacion' in d ? estados.find((e) => e.valor === d.estado_asignacion)?.etiqueta : ''

const filtro = (): FiltroDocumentos => ({
  desde: desde.value ? aIso(desde.value) : null,
  hasta: hasta.value ? aIso(hasta.value) : null,
  proveedor_id: proveedorId.value,
  ...(esGuias.value && { estado: estado.value }),
})

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

async function buscar() {
  const id = proyectoId.value
  if (!id || rangoInvalido.value) return
  const actual = tipo.value
  buscado = filtro()
  cargando.value = true
  seleccion.value = []
  try {
    const encontrados =
      actual === 'guias'
        ? await documentosServicio.listarGuias(id, buscado)
        : await documentosServicio.listarFacturas(id, buscado)
    // Si se cambió de proyecto o de tipo mientras cargaba, esta respuesta ya no aplica.
    if (id === proyectoId.value && actual === tipo.value) filas.value = encontrados
  } catch (error) {
    avisarError(error)
  } finally {
    cargando.value = false
  }
}

async function descargar(pedido: PedidoZip) {
  const id = proyectoId.value
  if (!id) return
  descargando.value = true
  try {
    const { blob, nombre } = await documentosServicio.descargarZip(id, tipo.value, pedido)
    const enlace = document.createElement('a')
    enlace.href = URL.createObjectURL(blob)
    enlace.download = nombre
    enlace.click()
    URL.revokeObjectURL(enlace.href)
  } catch (error) {
    avisarError(error)
  } finally {
    descargando.value = false
  }
}

watch(
  [tipo, () => proyectoActual.cargado && proyectoId.value],
  () => {
    if (!proyectoActual.cargado) return
    filas.value = []
    buscar()
  },
  { immediate: true },
)

onMounted(async () => {
  try {
    await proyectoActual.cargar()
    proveedores.value = await facturasServicio.proveedores()
  } catch (error) {
    avisarError(error)
    cargando.value = false
  }
})
</script>

<template>
  <PaginaListado
    :titulo="proyecto ? `Exportar documentos – ${proyecto.nombre}` : 'Exportar documentos'"
    subtitulo="Descargue en un ZIP las fotos de las guías o los archivos de las facturas"
  >
    <template #acciones>
      <Button
        label="Descargar seleccionados"
        icon="pi pi-check-square"
        outlined
        :loading="descargando"
        :disabled="!seleccion.length"
        @click="descargar({ ids: seleccion.map((d) => d.id) })"
      />
      <Button
        label="Descargar todo lo filtrado"
        icon="pi pi-download"
        :loading="descargando"
        :disabled="!filas.length || rangoInvalido"
        @click="descargar(buscado)"
      />
    </template>

    <template #herramientas>
      <FiltrosDocumentos
        v-model:tipo="tipo"
        v-model:desde="desde"
        v-model:hasta="hasta"
        v-model:proveedor-id="proveedorId"
        v-model:estado="estado"
        :proveedores="proveedores"
        :estados="estados"
        :rango-invalido="rangoInvalido"
        @buscar="buscar"
      />
    </template>

    <TablaCrud
      v-model:seleccion="seleccion"
      seleccionable
      :filas="filas"
      :columnas="columnas"
      clave="id"
      :vacio="`No hay ${esGuias ? 'guías' : 'facturas'} que coincidan con los filtros`"
      :cargando="cargando"
    >
      <template #col-numero="{ fila }">
        <span class="font-medium">{{ fila.numero }}</span>
        <span class="block text-sm text-surface-500 sm:hidden">{{
          fila.proveedor.razon_social
        }}</span>
      </template>
      <template #col-fecha="{ fila }">{{ formatoFecha(fila.fecha) }}</template>
      <template #col-proveedor="{ fila }">{{ fila.proveedor.razon_social }}</template>
      <template #col-volumen_m3="{ fila }">{{ decimales(fila.volumen_m3) }}</template>
      <template #col-estado_asignacion="{ fila }">{{ etiquetaEstado(fila) }}</template>
      <template #col-total="{ fila }">{{ 'total' in fila ? soles(fila.total) : '' }}</template>
      <template #col-archivo="{ fila }">
        <Tag v-if="!tieneArchivo(fila)" value="Sin archivo" severity="warn" />
        <span v-else>Sí</span>
      </template>
    </TablaCrud>
  </PaginaListado>
</template>
