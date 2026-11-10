<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useToast } from 'primevue/usetoast'
import Chart from 'primevue/chart'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Select from 'primevue/select'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import { desperdicioServicio } from '@/services/desperdicio'
import { decimales } from '@/services/presupuesto'
import type { ControlDesperdicio, DetalleDesperdicio, FilaDesperdicio } from '@/types/desperdicio'
import { mensajeDeError } from '@/api/http'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import { useAuthStore } from '@/stores/auth'

const toast = useToast()
const proyectoActual = useProyectoActualStore()
const { proyecto, proyectoId } = storeToRefs(proyectoActual)
// CU13 extendido por CU16: el reporte lo generan Oficina técnica y Gerencia, no Producción.
const router = useRouter()
const puedeReportar = useAuthStore().puedeVer('oficina_tecnica', 'gerencia')

const detalles: { valor: DetalleDesperdicio; nombre: string }[] = [
  { valor: 'elemento', nombre: 'Elemento estructural' },
  { valor: 'nivel', nombre: 'Nivel' },
  { valor: 'sector', nombre: 'Sector' },
]

const detalle = ref<DetalleDesperdicio>('sector')
const control = ref<ControlDesperdicio | null>(null)
const cargando = ref(true)

const filas = computed(() => control.value?.filas ?? [])
// Flujo alternativo 1: sin guías asignadas no hay nada que comparar.
const sinVaciados = computed(() => !!control.value && !control.value.hay_vaciados)

// Las columnas siguen a la última respuesta, no al selector: mientras carga el nuevo detalle, la
// tabla sigue mostrando el anterior completo (encabezados y filas).
const detalleMostrado = computed(() => control.value?.detalle ?? detalle.value)

const columnas = computed(() => [
  {
    campo: 'etiqueta',
    encabezado: detalles.find((d) => d.valor === detalleMostrado.value)!.nombre,
  },
  ...(detalleMostrado.value === 'sector'
    ? [{ campo: 'nivel', encabezado: 'Nivel', secundaria: true }]
    : []),
  { campo: 'presupuestado_m3', encabezado: 'Presup. m³', numerica: true },
  { campo: 'vaciado_m3', encabezado: 'Vaciado m³', numerica: true },
  { campo: 'diferencia_m3', encabezado: 'Dif. m³', numerica: true },
  { campo: 'desperdicio_pct', encabezado: '% Desp.', numerica: true },
])

/** Rótulo del gráfico, único aunque los nombres se repitan («Piso 1 · Sector A»). */
function rotulo(fila: FilaDesperdicio) {
  if (detalleMostrado.value === 'sector') return `${fila.nivel} · ${fila.etiqueta}`
  if (detalleMostrado.value === 'elemento' && etiquetaRepetida(fila)) {
    return `${fila.etiqueta} (${fila.item ?? fila.descripcion})`
  }
  return fila.etiqueta
}

const etiquetaRepetida = (fila: FilaDesperdicio) =>
  filas.value.filter((f) => f.etiqueta === fila.etiqueta).length > 1

const datosGrafico = computed(() => ({
  labels: filas.value.map(rotulo),
  datasets: [
    {
      label: 'Presupuestado m³',
      backgroundColor: '#94a3b8',
      data: filas.value.map((f) => f.presupuestado_m3),
    },
    {
      label: 'Vaciado m³',
      // Rojo donde se supera el límite con desperdicio (paso 4).
      backgroundColor: filas.value.map((f) => (f.supera_limite ? '#dc2626' : '#2563eb')),
      data: filas.value.map((f) => f.vaciado_m3),
    },
  ],
}))

const opcionesGrafico = {
  maintainAspectRatio: false,
  plugins: { legend: { position: 'bottom' as const } },
  scales: { y: { beginAtZero: true, title: { display: true, text: 'm³' } } },
}

const signo = (n: number) => (n > 0 ? '+' : '') + decimales(n)

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

// Número de la última consulta: si se cambia de proyecto o de detalle mientras carga, la respuesta
// anterior ya no aplica aunque llegue después.
let ultimaConsulta = 0

/** CU13 pasos 2–4: se consulta al elegir el proyecto o el nivel de detalle. */
async function consultar() {
  const id = proyectoId.value
  if (!id) return
  const numero = ++ultimaConsulta
  cargando.value = true
  try {
    const resultado = await desperdicioServicio.consultar(id, detalle.value)
    if (numero === ultimaConsulta) control.value = resultado
  } catch (error) {
    if (numero === ultimaConsulta) avisarError(error)
  } finally {
    if (numero === ultimaConsulta) cargando.value = false
  }
}

watch(
  () => proyectoActual.cargado && proyectoId.value,
  () => {
    if (!proyectoActual.cargado) return
    control.value = null
    consultar()
  },
  { immediate: true },
)

// Las filas anteriores quedan hasta que llega la nueva respuesta: la tabla no cambia a medias.
watch(detalle, () => {
  if (proyectoActual.cargado) consultar()
})

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
    :titulo="proyecto ? `Control de desperdicio – ${proyecto.nombre}` : 'Control de desperdicio'"
    subtitulo="Volumen presupuestado frente al vaciado, con su porcentaje de desperdicio"
  >
    <template v-if="puedeReportar && proyectoId" #acciones>
      <Button
        label="Corte de mes"
        icon="pi pi-calendar"
        outlined
        data-corte-mes
        @click="router.push('/reportes/corte-mes')"
      />
    </template>
    <template #herramientas>
      <div class="flex flex-col gap-1 sm:w-60">
        <label for="detalle" class="text-sm font-medium">Detalle por</label>
        <Select
          v-model="detalle"
          input-id="detalle"
          :options="detalles"
          option-label="nombre"
          option-value="valor"
          fluid
        />
      </div>
    </template>

    <Message v-if="sinVaciados" severity="info" class="m-3" data-sin-vaciados>
      Aún no hay vaciados registrados
    </Message>

    <TablaCrud
      :filas="filas"
      :columnas="columnas"
      clave="id"
      vacio="Este proyecto aún no tiene sectores de vaciado"
      :cargando="cargando"
      :clase-fila="(f) => (f.supera_limite ? 'fila-excedida' : undefined)"
    >
      <template #col-etiqueta="{ fila }">
        <span>{{ fila.etiqueta }}</span>
        <!-- Dos partidas pueden tener el mismo elemento y f'c: el ítem las distingue. -->
        <span
          v-if="detalleMostrado === 'elemento' && fila.item"
          class="block text-sm text-surface-500"
        >
          Ítem {{ fila.item }}
        </span>
        <span
          v-else-if="detalleMostrado === 'sector'"
          class="block text-sm text-surface-500 sm:hidden"
        >
          {{ fila.nivel }}
        </span>
      </template>
      <template #col-nivel="{ fila }">{{ fila.nivel }}</template>
      <template #col-presupuestado_m3="{ fila }">{{ decimales(fila.presupuestado_m3) }}</template>
      <template #col-vaciado_m3="{ fila }">{{ decimales(fila.vaciado_m3) }}</template>
      <template #col-diferencia_m3="{ fila }">{{ signo(fila.diferencia_m3) }}</template>
      <template #col-desperdicio_pct="{ fila }">
        <span v-if="fila.desperdicio_pct === null">—</span>
        <span v-else :class="{ 'font-semibold text-red-700': fila.supera_limite }">
          {{ signo(fila.desperdicio_pct) }} %
        </span>
      </template>
    </TablaCrud>

    <div v-if="filas.length" class="border-t border-surface-200 p-3">
      <h2 class="mb-2 text-sm font-medium">Presupuestado vs. vaciado</h2>
      <Chart type="bar" :data="datosGrafico" :options="opcionesGrafico" class="h-72" />
    </div>
  </PaginaListado>
</template>

<style scoped>
/* CU13 paso 4: lo que supera el factor de desperdicio, en rojo. */
:deep(.fila-excedida) > td {
  background-color: var(--p-red-50);
}
:deep(.fila-excedida) > td:first-child {
  box-shadow: inset 3px 0 0 var(--p-red-500);
}
</style>
