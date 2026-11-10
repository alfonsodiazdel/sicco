<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Select from 'primevue/select'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaReporteCorteMes from '@/components/organismos/TablaReporteCorteMes.vue'
import { reportesServicio } from '@/services/reportes'
import type { FormatoEat, ReporteCorteMes } from '@/types/reportes'
import { erroresDeValidacion, esErrorApi, mensajeDeError } from '@/api/http'
import { useProyectoActualStore } from '@/stores/proyectoActual'

const toast = useToast()
const route = useRoute()
const proyectoActual = useProyectoActualStore()
const { proyecto, proyectoId } = storeToRefs(proyectoActual)

const MESES = [
  'Enero',
  'Febrero',
  'Marzo',
  'Abril',
  'Mayo',
  'Junio',
  'Julio',
  'Agosto',
  'Setiembre',
  'Octubre',
  'Noviembre',
  'Diciembre',
]

const ahora = new Date()
const periodoActual = ahora.getFullYear() * 100 + ahora.getMonth() + 1

/** AAAAMM del inicio del proyecto; sin proyecto, sin límite inferior. */
const periodoInicio = computed(() => {
  const inicio = proyecto.value?.fecha_inicio
  if (!inicio) return 0
  const [a = 0, m = 1] = inicio.split('-').map(Number)
  return a * 100 + m
})

/** Por defecto el último mes cerrado; si el proyecto empezó este mes, el mes en curso. */
function periodoPorDefecto() {
  // Desde el dashboard (CU17) puede llegar el mes ya elegido: ?anio=2026&mes=10.
  const consulta = Number(route?.query.anio) * 100 + Number(route?.query.mes)
  if (Number.isInteger(consulta) && consulta >= periodoInicio.value && consulta <= periodoActual) {
    return consulta
  }
  const anterior = ahora.getMonth() === 0 ? (ahora.getFullYear() - 1) * 100 + 12 : periodoActual - 1
  return Math.max(anterior, Math.min(periodoInicio.value, periodoActual))
}

const anio = ref<number | null>(null)
const mes = ref<number | null>(null)

const anios = computed(() => {
  const desde = Math.floor(periodoInicio.value / 100) || ahora.getFullYear()
  const lista: number[] = []
  for (let a = ahora.getFullYear(); a >= desde; a--) lista.push(a)
  return lista
})

// Solo los meses entre el inicio del proyecto y el mes actual.
const meses = computed(() =>
  MESES.map((nombre, i) => ({ nombre, valor: i + 1 })).filter(({ valor }) => {
    if (!anio.value) return true
    const periodo = anio.value * 100 + valor
    return periodo >= periodoInicio.value && periodo <= periodoActual
  }),
)

function fijarPeriodo(periodo: number) {
  anio.value = Math.floor(periodo / 100)
  mes.value = periodo % 100
}

const reporte = ref<ReporteCorteMes | null>(null)
const mensaje = ref<string | null>(null)
const errorMes = ref<string | null>(null)
const generando = ref(false)
const descargando = ref<FormatoEat | null>(null)

const puedeGenerar = computed(() => !!proyectoId.value && !!anio.value && !!mes.value)

// Una respuesta que llega después de cambiar el proyecto o el mes ya no corresponde a lo que se ve.
let ultimaConsulta = 0

function limpiar() {
  ultimaConsulta++
  reporte.value = null
  mensaje.value = null
  errorMes.value = null
  generando.value = false
}

function textoDeError(error: unknown) {
  if (esErrorApi(error) && error.response?.status === 401) {
    return 'La sesión expiró. Vuelva a iniciar sesión.'
  }
  return mensajeDeError(error)
}

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: textoDeError(error), life: 5000 })
}

/** CU16 pasos 2–4. */
async function generar() {
  const id = proyectoId.value
  if (!id || !anio.value || !mes.value) return
  limpiar()
  const numero = ultimaConsulta
  generando.value = true
  try {
    const resultado = await reportesServicio.corteMes(id, anio.value, mes.value)
    if (numero !== ultimaConsulta) return
    reporte.value = resultado.reporte
    mensaje.value = resultado.reporte ? null : resultado.mensaje
  } catch (error) {
    if (numero !== ultimaConsulta) return
    const errores = erroresDeValidacion(error)
    const enMes = errores.mes ?? errores.anio
    if (enMes) errorMes.value = enMes
    else avisarError(error)
  } finally {
    if (numero === ultimaConsulta) generando.value = false
  }
}

// Se descarga el reporte que está a la vista, con su proyecto y su mes.
async function descargar(formato: FormatoEat) {
  const actual = reporte.value
  if (!actual) return
  descargando.value = formato
  try {
    const archivo = await reportesServicio.descargarCorteMes(
      actual.proyecto.id,
      actual.anio,
      actual.mes,
      formato,
    )
    const enlace = document.createElement('a')
    enlace.href = URL.createObjectURL(archivo)
    const periodo = `${actual.anio}${String(actual.mes).padStart(2, '0')}`
    enlace.download = `reporte-corte-mes-${actual.proyecto.codigo}-${periodo}.${formato === 'excel' ? 'xlsx' : 'pdf'}`
    enlace.click()
    URL.revokeObjectURL(enlace.href)
  } catch (error) {
    avisarError(error)
  } finally {
    descargando.value = null
  }
}

watch([proyectoId, anio, mes], limpiar)

// Al cambiar de proyecto o de año, el mes elegido puede quedar fuera del rango.
watch(
  () => proyecto.value?.id,
  () => fijarPeriodo(periodoPorDefecto()),
  { immediate: true },
)
watch(meses, (lista) => {
  if (mes.value && !lista.some((m) => m.valor === mes.value)) mes.value = null
})

const titulo = computed(() => {
  const base = 'Corte de mes'
  return proyecto.value ? `${base} – ${proyecto.value.nombre}` : base
})

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
    :titulo="titulo"
    subtitulo="Concreto recibido, vaciado y facturado en el mes, lo pendiente de facturar al cierre y el desperdicio acumulado. Montos sin IGV."
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
          <label for="mes" class="text-sm font-medium">Mes</label>
          <Select
            v-model="mes"
            input-id="mes"
            :options="meses"
            option-label="nombre"
            option-value="valor"
            placeholder="Mes"
            :invalid="!!errorMes"
            fluid
          />
        </div>
        <div class="flex flex-col gap-1 sm:w-32">
          <label for="anio" class="text-sm font-medium">Año</label>
          <Select v-model="anio" input-id="anio" :options="anios" placeholder="Año" fluid />
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

    <Message v-if="errorMes" severity="error" class="m-3" data-error-mes>{{ errorMes }}</Message>
    <Message v-if="mensaje" severity="info" class="m-3" data-sin-datos>{{ mensaje }}</Message>
    <Message v-if="reporte?.parcial" severity="warn" class="m-3" data-parcial>
      Mes en curso: el corte es parcial hasta hoy.
    </Message>

    <TablaReporteCorteMes v-if="reporte" :reporte="reporte" />
    <p
      v-else-if="proyectoActual.cargado && !proyectoId"
      class="p-6 text-center text-sm text-surface-500"
      data-sin-proyecto
    >
      No tiene proyectos activos asignados para generar el reporte.
    </p>
    <p v-else-if="!mensaje && !errorMes" class="p-6 text-center text-sm text-surface-500">
      Elija el mes y el año y pulse «Generar».
    </p>
  </PaginaListado>
</template>
