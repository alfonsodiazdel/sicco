<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import VisorFotoGuia from '@/components/organismos/VisorFotoGuia.vue'
import { asignacionesServicio } from '@/services/asignaciones'
import { decimales } from '@/services/presupuesto'
import { aIso } from '@/validaciones/proyecto'
import type { FiltroSinSector, GuiaPendiente } from '@/types/guias'
import { mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'

const toast = useToast()
const confirm = useConfirm()
const router = useRouter()
const auth = useAuthStore()
const proyectoActual = useProyectoActualStore()

// Oficina técnica y el administrador consultan; solo Producción asigna (CU09).
const puedeAsignar = computed(() => auth.tieneRol('ingeniero_produccion'))
// El almacenero revisa lo que registró y elimina la guía subida al proyecto equivocado.
const puedeEliminar = computed(() => auth.tieneRol('almacenero'))

const { proyecto, proyectoId } = storeToRefs(proyectoActual)
const desde = ref<Date | null>(null)
const hasta = ref<Date | null>(null)
const guias = ref<GuiaPendiente[]>([])
const cargando = ref(true)
const exportando = ref(false)

const rangoInvalido = computed(() => !!desde.value && !!hasta.value && hasta.value < desde.value)
const atrasadas = computed(() => guias.value.filter((g) => g.atrasada).length)

const columnas = [
  { campo: 'numero', encabezado: 'N° de guía' },
  { campo: 'fecha', encabezado: 'Fecha' },
  { campo: 'proveedor', encabezado: 'Proveedor', secundaria: true },
  { campo: 'resistencia', encabezado: 'Resistencia', secundaria: true },
  { campo: 'volumen_m3', encabezado: 'Volumen (m³)', numerica: true },
  { campo: 'dias_transcurridos', encabezado: 'Días sin asignar', numerica: true },
]

const formatoFecha = (iso: string) => iso.split('-').reverse().join('/')

const filtro = (): FiltroSinSector => ({
  desde: desde.value ? aIso(desde.value) : null,
  hasta: hasta.value ? aIso(hasta.value) : null,
})

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

/** CU10 pasos 2–3. */
async function buscar() {
  const id = proyectoId.value
  if (!id || rangoInvalido.value) return
  cargando.value = true
  try {
    const encontradas = await asignacionesServicio.listarSinSector(id, filtro())
    // Si se eligió otro proyecto mientras cargaba, esta respuesta ya no aplica.
    if (id === proyectoId.value) guias.value = encontradas
  } catch (error) {
    if (id === proyectoId.value) avisarError(error)
  } finally {
    if (id === proyectoId.value) cargando.value = false
  }
}

async function exportar() {
  const id = proyectoId.value
  if (!id || rangoInvalido.value) return
  exportando.value = true
  try {
    const archivo = await asignacionesServicio.exportarSinSector(id, filtro())
    const enlace = document.createElement('a')
    enlace.href = URL.createObjectURL(archivo)
    enlace.download = `guias-sin-sector-${proyecto.value?.codigo ?? id}.xlsx`
    enlace.click()
    URL.revokeObjectURL(enlace.href)
  } catch (error) {
    avisarError(error)
  } finally {
    exportando.value = false
  }
}

function confirmarEliminacion(guia: GuiaPendiente) {
  confirm.require({
    header: 'Eliminar guía',
    message: `¿Eliminar la guía ${guia.numero} de ${guia.proveedor.razon_social}? Si la subió al proyecto equivocado, regístrela de nuevo en el correcto.`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancelar', severity: 'secondary', text: true },
    acceptProps: { label: 'Eliminar', severity: 'danger' },
    accept: async () => {
      try {
        await asignacionesServicio.eliminarGuia(guia.id)
        toast.add({ severity: 'success', summary: 'Guía eliminada correctamente', life: 3000 })
        await buscar()
      } catch (error) {
        avisarError(error)
      }
    },
  })
}

// Al entrar ya se listan todas las pendientes: el caso de uso busca detectarlas a tiempo.
watch(
  () => proyectoActual.cargado && proyectoId.value,
  () => {
    if (!proyectoActual.cargado) return
    guias.value = []
    buscar()
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

const fecha = { dateFormat: 'dd/mm/yy', placeholder: 'dd/mm/aaaa', showClear: true, fluid: true }
</script>

<template>
  <PaginaListado
    :titulo="proyecto ? `Guías sin sector – ${proyecto.nombre}` : 'Guías sin sector'"
    subtitulo="Guías registradas que todavía tienen volumen sin asignar a un sector de vaciado"
  >
    <template #acciones>
      <Button
        label="Exportar a Excel"
        icon="pi pi-file-excel"
        outlined
        :loading="exportando"
        :disabled="!guias.length || rangoInvalido"
        @click="exportar"
      />
    </template>

    <template #herramientas>
      <form class="flex w-full flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="buscar">
        <div class="flex flex-col gap-1 sm:w-44">
          <label for="desde" class="text-sm font-medium">Desde</label>
          <DatePicker v-model="desde" input-id="desde" v-bind="fecha" />
        </div>
        <div class="flex flex-col gap-1 sm:w-44">
          <label for="hasta" class="text-sm font-medium">Hasta</label>
          <DatePicker
            v-model="hasta"
            input-id="hasta"
            v-bind="fecha"
            :invalid="rangoInvalido"
            :pt="{ pcInputText: { root: { 'aria-describedby': 'hasta-error' } } }"
          />
        </div>
        <Button type="submit" label="Buscar" icon="pi pi-search" :disabled="rangoInvalido" />
        <p v-if="rangoInvalido" id="hasta-error" class="text-sm text-red-600 sm:self-center">
          La fecha final debe ser igual o posterior a la inicial
        </p>
      </form>
    </template>

    <Message
      v-if="atrasadas"
      severity="error"
      icon="pi pi-exclamation-circle"
      class="m-3"
      data-atrasadas
    >
      {{
        atrasadas === 1
          ? '1 guía lleva más de 2 días sin asignar.'
          : `${atrasadas} guías llevan más de 2 días sin asignar.`
      }}
    </Message>

    <TablaCrud
      :filas="guias"
      :columnas="columnas"
      clave="id"
      vacio="No hay guías sin sector asignado"
      :cargando="cargando"
      :clase-fila="(g) => (g.atrasada ? 'guia-atrasada' : undefined)"
    >
      <template #col-numero="{ fila }">
        <span class="font-medium">{{ fila.numero }}</span>
        <Tag
          v-if="fila.estado_asignacion === 'parcial'"
          value="Parcial"
          severity="warn"
          class="ml-2"
        />
        <span class="block text-sm text-surface-500 sm:hidden">{{
          fila.proveedor.razon_social
        }}</span>
      </template>
      <template #col-fecha="{ fila }">{{ formatoFecha(fila.fecha) }}</template>
      <template #col-proveedor="{ fila }">{{ fila.proveedor.razon_social }}</template>
      <template #col-resistencia="{ fila }">{{ fila.resistencia.nombre }}</template>
      <template #col-volumen_m3="{ fila }">
        <span>{{ decimales(fila.volumen_m3) }}</span>
        <span v-if="fila.estado_asignacion === 'parcial'" class="block text-sm text-surface-500"
          >Pendiente {{ decimales(fila.volumen_pendiente_m3) }}</span
        >
      </template>
      <template #col-dias_transcurridos="{ fila }">
        <span :class="{ 'font-semibold text-red-700': fila.atrasada }">{{
          fila.dias_transcurridos
        }}</span>
      </template>
      <template #acciones="{ fila }">
        <VisorFotoGuia :guia-id="fila.id" :numero="fila.numero" />
        <Button
          v-if="puedeAsignar"
          label="Asignar"
          size="small"
          :aria-label="`Asignar guía ${fila.numero}`"
          @click="router.push({ name: 'asignar-guia', params: { id: fila.id } })"
        />
        <Button
          v-if="puedeEliminar && fila.eliminable"
          icon="pi pi-trash"
          severity="danger"
          text
          size="small"
          :aria-label="`Eliminar guía ${fila.numero}`"
          @click="confirmarEliminacion(fila)"
        />
      </template>
    </TablaCrud>
  </PaginaListado>
</template>

<style scoped>
/* CU10 paso 4: las guías con más de 2 días sin asignar, en rojo. */
:deep(.guia-atrasada) > td {
  background-color: var(--p-red-50);
}
:deep(.guia-atrasada) > td:first-child {
  box-shadow: inset 3px 0 0 var(--p-red-500);
}
</style>
