<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Chart from 'primevue/chart'
import Message from 'primevue/message'
import Select from 'primevue/select'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import { dashboardServicio } from '@/services/dashboard'
import { fechaHora, ubicacion } from '@/services/alertas'
import { decimales, soles } from '@/services/presupuesto'
import type { Dashboard, ProyectoDashboard } from '@/types/dashboard'
import { mensajeDeError } from '@/api/http'

const toast = useToast()
const router = useRouter()

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
/** Cuántos meses hacia atrás ofrece el filtro. */
const MESES_ATRAS = 24

const aPeriodo = (anio: number, mes: number) => `${anio}-${String(mes).padStart(2, '0')}`
const ahora = new Date()
const mesActual = aPeriodo(ahora.getFullYear(), ahora.getMonth() + 1)

/** Del mes actual hacia atrás: «Noviembre 2026» → '2026-11'. */
const periodos = Array.from({ length: MESES_ATRAS }, (_, i) => {
  const fecha = new Date(ahora.getFullYear(), ahora.getMonth() - i, 1)
  return {
    valor: aPeriodo(fecha.getFullYear(), fecha.getMonth() + 1),
    nombre: `${MESES[fecha.getMonth()]} ${fecha.getFullYear()}`,
  }
})

// CU17 paso 3: por defecto, el mes en curso.
const desde = ref(mesActual)
const hasta = ref(mesActual)
// Las cadenas AAAA-MM se ordenan como fechas.
const opcionesDesde = computed(() => periodos.filter((p) => p.valor <= hasta.value))
const opcionesHasta = computed(() => periodos.filter((p) => p.valor >= desde.value))

const dashboard = ref<Dashboard | null>(null)
const cargando = ref(true)

const indicadores = computed(() => dashboard.value?.indicadores)
const proyectos = computed(() => dashboard.value?.proyectos ?? [])
const alertas = computed(() => dashboard.value?.alertas.recientes ?? [])
const pendientes = computed(() => dashboard.value?.alertas.pendientes ?? 0)

const nombreMes = (periodo: string) => periodos.find((p) => p.valor === periodo)?.nombre ?? periodo
const rotuloPeriodo = computed(() =>
  desde.value === hasta.value
    ? nombreMes(hasta.value)
    : `${nombreMes(desde.value)} – ${nombreMes(hasta.value)}`,
)

const porcentaje = (valor: number | null | undefined) =>
  valor === null || valor === undefined ? '—' : `${decimales(valor)} %`

const tarjetas = computed(() => [
  {
    clave: 'activos',
    rotulo: 'Proyectos activos',
    valor: String(indicadores.value?.proyectos_activos ?? 0),
  },
  { clave: 'vaciado', rotulo: 'm³ vaciados', valor: decimales(indicadores.value?.vaciado_m3 ?? 0) },
  {
    clave: 'costo',
    rotulo: 'Costo S/ (sin IGV)',
    valor: soles(indicadores.value?.costo_soles ?? 0),
  },
  {
    clave: 'desperdicio',
    rotulo: '% Desperdicio',
    valor: porcentaje(indicadores.value?.desperdicio_pct),
    alerta: indicadores.value?.supera_limite,
    detalle:
      indicadores.value?.limite_pct !== null && indicadores.value?.limite_pct !== undefined
        ? `Límite ${decimales(indicadores.value.limite_pct)} %`
        : 'Sin sectores medidos',
  },
])

// CU17 paso 2: desperdicio por proyecto, en rojo el que pasa su límite.
const datosGrafico = computed(() => ({
  labels: proyectos.value.map((p) => p.codigo),
  datasets: [
    {
      type: 'bar' as const,
      label: '% Desperdicio',
      backgroundColor: proyectos.value.map((p) =>
        p.desperdicio.supera_limite ? '#dc2626' : '#2563eb',
      ),
      // Sin sectores medidos no hay barra: la etiqueta dice «Sin datos», no «0 %».
      data: proyectos.value.map((p) => p.desperdicio.desperdicio_pct),
      order: 2,
    },
    {
      type: 'line' as const,
      label: 'Límite %',
      borderColor: '#475569',
      backgroundColor: '#475569',
      showLine: false,
      pointStyle: 'line' as const,
      pointRadius: 14,
      pointBorderWidth: 3,
      data: proyectos.value.map((p) => p.desperdicio.limite_pct),
      order: 1,
    },
  ],
}))

const opcionesGrafico = {
  maintainAspectRatio: false,
  plugins: {
    legend: { position: 'bottom' as const },
    tooltip: {
      callbacks: {
        title: (items: { dataIndex: number }[]) => {
          const p = proyectos.value[items[0]?.dataIndex ?? -1]
          return p ? `${p.codigo} · ${p.nombre}` : ''
        },
      },
    },
  },
  // El margen deja sitio a la etiqueta sobre la barra más alta.
  scales: { y: { beginAtZero: true, grace: '10%', title: { display: true, text: '%' } } },
  // CU17 paso 4: clic en un proyecto → su control de desperdicio.
  onClick: (_evento: unknown, elementos: { index: number }[]) => {
    const p = proyectos.value[elementos[0]?.index ?? -1]
    if (p) verDesperdicio(p)
  },
}

/** Texto de cada barra: un 0 % medido no es lo mismo que un proyecto sin sectores medidos. */
const etiquetaDesperdicio = (pct: number | null) =>
  pct === null ? 'Sin datos' : `${decimales(pct)} %`

interface GraficoEtiquetable {
  ctx: CanvasRenderingContext2D
  scales: Record<string, { getPixelForValue(valor: number): number }>
}

/** Escribe sobre cada barra su % o «Sin datos» (Chart.js no trae etiquetas de datos). */
const etiquetasBarras = {
  id: 'etiquetasBarras',
  afterDatasetsDraw(grafico: GraficoEtiquetable) {
    const { ctx, scales } = grafico
    const { x, y } = scales
    if (!x || !y) return
    ctx.save()
    ctx.font = '12px sans-serif'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'bottom'
    proyectos.value.forEach((p, i) => {
      const pct = p.desperdicio.desperdicio_pct
      ctx.fillStyle = pct === null ? '#64748b' : p.desperdicio.supera_limite ? '#b91c1c' : '#1e293b'
      ctx.fillText(
        etiquetaDesperdicio(pct),
        x.getPixelForValue(i),
        y.getPixelForValue(pct ?? 0) - 4,
      )
    })
    ctx.restore()
  },
}

const columnasProyectos = [
  { campo: 'nombre', encabezado: 'Proyecto' },
  { campo: 'recibido_m3', encabezado: 'Recibido m³', numerica: true, secundaria: true },
  { campo: 'vaciado_m3', encabezado: 'Vaciado m³', numerica: true },
  { campo: 'costo_soles', encabezado: 'Costo S/', numerica: true },
  { campo: 'desperdicio', encabezado: '% Desp.', numerica: true },
]

const columnasAlertas = [
  { campo: 'sector', encabezado: 'Alerta' },
  { campo: 'proyecto', encabezado: 'Proyecto', secundaria: true },
  { campo: 'fecha', encabezado: 'Fecha' },
]

function verDesperdicio(p: ProyectoDashboard) {
  router.push({ path: '/desperdicio', query: { proyecto: p.id } })
}

/** CU17 paso 5 (extendido por CU16): el corte del último mes del rango. */
function corteMes(p: ProyectoDashboard) {
  const [anio, mes] = hasta.value.split('-').map(Number)
  router.push({ path: '/reportes/corte-mes', query: { proyecto: p.id, anio, mes } })
}

// Una respuesta que llega después de cambiar el rango ya no corresponde a lo que se ve.
let ultimaConsulta = 0

/** Se calcula en cada consulta: refleja cada guía, asignación o factura ya registrada. */
async function consultar() {
  const numero = ++ultimaConsulta
  cargando.value = true
  try {
    const resultado = await dashboardServicio.consultar(desde.value, hasta.value)
    if (numero === ultimaConsulta) dashboard.value = resultado
  } catch (error) {
    if (numero === ultimaConsulta) {
      toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
    }
  } finally {
    if (numero === ultimaConsulta) cargando.value = false
  }
}

watch([desde, hasta], consultar)
onMounted(consultar)
</script>

<template>
  <PaginaListado
    titulo="Dashboard gerencial"
    :subtitulo="`Costo y desperdicio de sus proyectos activos · ${rotuloPeriodo}`"
  >
    <template #acciones>
      <Button
        icon="pi pi-refresh"
        label="Actualizar"
        outlined
        :loading="cargando"
        data-actualizar
        @click="consultar"
      />
    </template>
    <template #herramientas>
      <div class="flex flex-col gap-3 sm:flex-row">
        <div class="flex flex-col gap-1 sm:w-52">
          <label for="desde" class="text-sm font-medium">Desde</label>
          <Select
            v-model="desde"
            input-id="desde"
            :options="opcionesDesde"
            option-label="nombre"
            option-value="valor"
            fluid
          />
        </div>
        <div class="flex flex-col gap-1 sm:w-52">
          <label for="hasta" class="text-sm font-medium">Hasta</label>
          <Select
            v-model="hasta"
            input-id="hasta"
            :options="opcionesHasta"
            option-label="nombre"
            option-value="valor"
            fluid
          />
        </div>
      </div>
    </template>

    <Message v-if="dashboard?.mensaje" severity="info" class="m-3" data-sin-proyectos>
      {{ dashboard.mensaje }}
    </Message>
    <Message
      v-else-if="dashboard?.periodo.parcial"
      severity="secondary"
      size="small"
      class="m-3"
      data-parcial
    >
      El mes en curso aún no cierra: las cifras pueden cambiar.
    </Message>

    <!-- CU17 paso 1: los cuatro indicadores. -->
    <div class="grid grid-cols-2 gap-3 p-3 lg:grid-cols-4">
      <div
        v-for="t in tarjetas"
        :key="t.clave"
        class="rounded-lg border p-4 text-center"
        :class="t.alerta ? 'border-red-300 bg-red-50' : 'border-surface-200'"
        :data-indicador="t.clave"
      >
        <p
          class="text-xl font-semibold whitespace-nowrap tabular-nums xl:text-2xl"
          :class="t.alerta ? 'text-red-700' : 'text-surface-900'"
        >
          {{ t.valor }}
        </p>
        <p class="mt-1 text-sm text-surface-600">{{ t.rotulo }}</p>
        <p v-if="t.detalle" class="text-xs text-surface-500">{{ t.detalle }}</p>
      </div>
    </div>

    <template v-if="proyectos.length">
      <div class="border-t border-surface-200 p-3">
        <h2 class="mb-2 text-sm font-medium">Desperdicio por proyecto</h2>
        <Chart
          type="bar"
          :data="datosGrafico"
          :options="opcionesGrafico"
          :plugins="[etiquetasBarras]"
          class="h-72"
        />
      </div>

      <TablaCrud
        :filas="proyectos"
        :columnas="columnasProyectos"
        clave="id"
        vacio="No hay proyectos activos"
        :cargando="cargando"
        :clase-fila="(p) => (p.desperdicio.supera_limite ? 'fila-excedida' : undefined)"
      >
        <template #col-nombre="{ fila }">
          <span>{{ fila.codigo }}</span>
          <span class="block text-sm text-surface-500">{{ fila.nombre }}</span>
        </template>
        <template #col-recibido_m3="{ fila }">{{ decimales(fila.recibido_m3) }}</template>
        <template #col-vaciado_m3="{ fila }">{{ decimales(fila.vaciado_m3) }}</template>
        <template #col-costo_soles="{ fila }">{{ decimales(fila.costo_soles) }}</template>
        <template #col-desperdicio="{ fila }">
          <span
            v-if="fila.desperdicio.desperdicio_pct === null"
            class="text-sm text-surface-500"
            title="Aún no hay sectores culminados ni excedidos"
          >
            Sin datos
          </span>
          <span v-else :class="{ 'font-semibold text-red-700': fila.desperdicio.supera_limite }">
            {{ etiquetaDesperdicio(fila.desperdicio.desperdicio_pct) }}
          </span>
        </template>
        <template #acciones="{ fila }">
          <div class="flex">
            <Button
              icon="pi pi-percentage"
              text
              rounded
              severity="secondary"
              :aria-label="`Control de desperdicio de ${fila.codigo}`"
              title="Control de desperdicio"
              data-ver-desperdicio
              @click="verDesperdicio(fila)"
            />
            <Button
              icon="pi pi-calendar"
              text
              rounded
              severity="secondary"
              :aria-label="`Corte de ${nombreMes(hasta)} de ${fila.codigo}`"
              :title="`Corte de ${nombreMes(hasta)}`"
              data-corte-mes
              @click="corteMes(fila)"
            />
          </div>
        </template>
      </TablaCrud>
    </template>

    <div class="border-t border-surface-200">
      <div class="flex items-center justify-between gap-2 p-3">
        <h2 class="text-sm font-medium">
          Alertas abiertas
          <span class="text-surface-500" data-pendientes>({{ pendientes }})</span>
        </h2>
        <Button
          v-if="pendientes"
          label="Ver todas"
          text
          size="small"
          data-ver-alertas
          @click="router.push('/alertas')"
        />
      </div>
      <TablaCrud
        :filas="alertas"
        :columnas="columnasAlertas"
        clave="id"
        vacio="No hay alertas abiertas"
        :cargando="cargando"
      >
        <template #col-sector="{ fila }">
          <span>{{ ubicacion(fila) }}</span>
          <span class="block text-sm text-red-700">
            +{{ decimales(fila.exceso_m3) }} m³ sobre el límite
          </span>
        </template>
        <template #col-proyecto="{ fila }">
          <span>{{ fila.proyecto.codigo }}</span>
          <span class="block text-sm text-surface-500">{{ fila.proyecto.nombre }}</span>
        </template>
        <template #col-fecha="{ fila }">
          <span class="whitespace-nowrap">{{ fechaHora(fila.fecha) }}</span>
        </template>
      </TablaCrud>
    </div>
  </PaginaListado>
</template>

<style scoped>
:deep(.fila-excedida) > td {
  background-color: var(--p-red-50);
}
:deep(.fila-excedida) > td:first-child {
  box-shadow: inset 3px 0 0 var(--p-red-500);
}
</style>
