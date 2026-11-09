<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Message from 'primevue/message'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaReporteEat from '@/components/organismos/TablaReporteEat.vue'
import { reportesServicio } from '@/services/reportes'
import { aIso } from '@/validaciones/proyecto'
import type { FormatoEat, ReporteEat } from '@/types/reportes'
import { erroresDeValidacion, esErrorApi, mensajeDeError } from '@/api/http'
import { useProyectoActualStore } from '@/stores/proyectoActual'

const toast = useToast()
const proyectoActual = useProyectoActualStore()
const { proyecto, proyectoId } = storeToRefs(proyectoActual)

function inicioDeHoy() {
  const ahora = new Date()
  return new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate())
}

const hoy = inicioDeHoy()
const fechaCorte = ref<Date | null>(hoy)
const reporte = ref<ReporteEat | null>(null)
const mensaje = ref<string | null>(null)
const errorFecha = ref<string | null>(null)
const generando = ref(false)
const descargando = ref<FormatoEat | null>(null)

const puedeGenerar = computed(() => !!proyectoId.value && !!fechaCorte.value)

// Número de la última consulta: una respuesta que llega después de cambiar el proyecto o la fecha
// ya no corresponde a lo que se ve.
let ultimaConsulta = 0

function limpiar() {
  ultimaConsulta++
  reporte.value = null
  mensaje.value = null
  errorFecha.value = null
  generando.value = false
}

// El 401 trae el texto técnico del framework, en inglés.
function textoDeError(error: unknown) {
  if (esErrorApi(error) && error.response?.status === 401) {
    return 'La sesión expiró. Vuelva a iniciar sesión.'
  }
  return mensajeDeError(error)
}

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: textoDeError(error), life: 5000 })
}

/** CU15 pasos 2–4. */
async function generar() {
  const id = proyectoId.value
  if (!id || !fechaCorte.value) return
  limpiar()
  const numero = ultimaConsulta
  generando.value = true
  try {
    const resultado = await reportesServicio.eat(id, aIso(fechaCorte.value))
    if (numero !== ultimaConsulta) return
    reporte.value = resultado.reporte
    mensaje.value = resultado.reporte ? null : resultado.mensaje
  } catch (error) {
    if (numero !== ultimaConsulta) return
    const enFecha = erroresDeValidacion(error).fecha_corte
    if (enFecha) errorFecha.value = enFecha
    else avisarError(error)
  } finally {
    if (numero === ultimaConsulta) generando.value = false
  }
}

// Se descarga el reporte que está a la vista, con su proyecto y su fecha de corte.
async function descargar(formato: FormatoEat) {
  const actual = reporte.value
  if (!actual) return
  descargando.value = formato
  try {
    const archivo = await reportesServicio.descargarEat(
      actual.proyecto.id,
      actual.fecha_corte,
      formato,
    )
    const enlace = document.createElement('a')
    enlace.href = URL.createObjectURL(archivo)
    const fecha = actual.fecha_corte.replace(/-/g, '')
    enlace.download = `reporte-eat-${actual.proyecto.codigo}-${fecha}.${formato === 'excel' ? 'xlsx' : 'pdf'}`
    enlace.click()
    URL.revokeObjectURL(enlace.href)
  } catch (error) {
    avisarError(error)
  } finally {
    descargando.value = null
  }
}

watch([proyectoId, fechaCorte], limpiar)

onMounted(async () => {
  try {
    await proyectoActual.cargar()
  } catch (error) {
    avisarError(error)
  }
})
</script>

<template>
  <PaginaListado
    :titulo="proyecto ? `Reporte EAT – ${proyecto.nombre}` : 'Reporte EAT'"
    subtitulo="Presupuesto, consumido, saldo y estimado a término del concreto. Montos sin IGV."
  >
    <template #acciones>
      <Button
        label="PDF"
        icon="pi pi-file-pdf"
        outlined
        :loading="descargando === 'pdf'"
        :disabled="!reporte || !!descargando"
        @click="descargar('pdf')"
      />
      <Button
        label="Excel"
        icon="pi pi-file-excel"
        outlined
        :loading="descargando === 'excel'"
        :disabled="!reporte || !!descargando"
        @click="descargar('excel')"
      />
    </template>

    <template #herramientas>
      <form class="flex w-full flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="generar">
        <div class="flex flex-col gap-1 sm:w-44">
          <label for="fecha-corte" class="text-sm font-medium">Fecha de corte</label>
          <DatePicker
            v-model="fechaCorte"
            input-id="fecha-corte"
            date-format="dd/mm/yy"
            placeholder="dd/mm/aaaa"
            :max-date="hoy"
            :invalid="!!errorFecha"
            fluid
          />
        </div>
        <Button
          type="submit"
          label="Generar"
          icon="pi pi-play"
          :loading="generando"
          :disabled="!puedeGenerar"
        />
      </form>
    </template>

    <Message v-if="errorFecha" severity="error" class="m-3" data-error-fecha>
      {{ errorFecha }}
    </Message>
    <Message v-if="mensaje" severity="info" class="m-3" data-sin-datos>{{ mensaje }}</Message>

    <TablaReporteEat v-if="reporte" :reporte="reporte" />
    <p
      v-else-if="proyectoActual.cargado && !proyectoId"
      class="p-6 text-center text-sm text-surface-500"
      data-sin-proyecto
    >
      No tiene proyectos activos asignados para generar el reporte.
    </p>
    <p v-else-if="!mensaje && !errorFecha" class="p-6 text-center text-sm text-surface-500">
      Elija la fecha de corte y pulse «Generar».
    </p>
  </PaginaListado>
</template>
