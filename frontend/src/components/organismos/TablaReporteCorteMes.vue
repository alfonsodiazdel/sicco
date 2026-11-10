<script setup lang="ts">
import { computed } from 'vue'
import Tag from 'primevue/tag'
import Tooltip from 'primevue/tooltip'
import { decimales, soles } from '@/services/presupuesto'
import type { ReporteCorteMes } from '@/types/reportes'

const props = defineProps<{ reporte: ReporteCorteMes }>()

const vTooltip = Tooltip

/** `YYYY-MM-DD` a `dd/mm/aaaa`, sin pasar por Date (la zona horaria movería el día). */
const fecha = (iso: string) => iso.split('-').reverse().join('/')

/** «1 guía», «3 guías». */
const cuenta = (n: number, uno: string, varios: string) => `${n} ${n === 1 ? uno : varios}`

const r = computed(() => props.reporte.resumen)
const d = computed(() => props.reporte.desperdicio)

// Las cuatro cifras del prototipo (Figura 28), en m³ y en soles.
const indicadores = computed(() => [
  {
    clave: 'recibido',
    titulo: 'Recibido',
    valor: `${decimales(r.value.recibido_m3)} m³`,
    detalle: `${cuenta(r.value.guias_recibidas, 'guía', 'guías')} del mes`,
    ayuda: 'Volumen de las guías con fecha en el mes.',
  },
  {
    clave: 'vaciado',
    titulo: 'Vaciado',
    valor: `${decimales(r.value.vaciado_m3)} m³`,
    detalle: 'asignado a sectores',
    ayuda: 'Lo que de las guías del mes se asignó a sectores de vaciado.',
  },
  {
    clave: 'facturado',
    titulo: 'Facturado',
    valor: soles(r.value.facturado_soles),
    detalle: `${decimales(r.value.facturado_m3)} m³ · ${cuenta(r.value.facturas, 'factura', 'facturas')}`,
    ayuda:
      'Subtotal de las facturas con fecha en el mes, con sus cargos (bombeo, aditivos…). El volumen es solo el concreto.',
  },
  {
    clave: 'pendiente',
    titulo: 'Pendiente de facturar',
    valor: soles(r.value.pendiente_soles),
    detalle: `${decimales(r.value.pendiente_m3)} m³ · ${cuenta(r.value.guias_pendientes, 'guía', 'guías')}`,
    ayuda:
      "Acumulado al cierre: guías hasta fin de mes sin factura fechada hasta ese día. Cada una al precio de su factura, al último facturado de su f'c o al presupuestado.",
  },
])

const ayudaDesperdicio =
  'Acumulado al cierre, en los sectores culminados hasta fin de mes y en los abiertos que ya superan lo presupuestado. % = (vaciado − presupuestado) / presupuestado × 100.'

const celda = 'px-3 py-2 text-right font-mono whitespace-nowrap tabular-nums'
</script>

<template>
  <div class="space-y-4 p-3">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div
        v-for="i in indicadores"
        :key="i.clave"
        class="rounded-xl border border-surface-200 bg-white p-4"
        :data-indicador="i.clave"
      >
        <abbr
          v-tooltip.top="i.ayuda"
          :aria-label="`${i.titulo}: ${i.ayuda}`"
          tabindex="0"
          class="cursor-help text-sm text-surface-500 underline decoration-dotted underline-offset-4"
          >{{ i.titulo }}</abbr
        >
        <p class="mt-1 font-mono text-xl font-semibold text-surface-900 tabular-nums">
          {{ i.valor }}
        </p>
        <p class="text-xs text-surface-500">{{ i.detalle }}</p>
      </div>
    </div>

    <div
      class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl border p-4 text-sm"
      :class="
        d.supera_limite
          ? 'border-red-200 bg-red-50 text-red-800'
          : 'border-surface-200 bg-white text-surface-700'
      "
      data-desperdicio
    >
      <abbr
        v-tooltip.top="ayudaDesperdicio"
        :aria-label="`Desperdicio acumulado: ${ayudaDesperdicio}`"
        tabindex="0"
        class="cursor-help font-medium underline decoration-dotted underline-offset-4"
        >Desperdicio acumulado</abbr
      >
      <template v-if="d.desperdicio_pct !== null">
        <span class="font-mono text-lg font-semibold tabular-nums"
          >{{ decimales(d.desperdicio_pct) }} %</span
        >
        <span
          >{{ decimales(d.vaciado_m3) }} m³ vaciados de {{ decimales(d.presupuestado_m3) }} m³
          presupuestados en {{ cuenta(d.sectores, 'sector', 'sectores') }}</span
        >
        <span v-if="d.supera_limite" class="font-medium">Supera el desperdicio permitido</span>
      </template>
      <span v-else>Aún no hay sectores culminados ni excedidos al cierre.</span>
    </div>

    <div class="overflow-x-auto rounded-xl border border-surface-200 bg-white">
      <table class="w-full border-collapse text-xs sm:text-sm">
        <thead>
          <tr class="border-b border-surface-200 text-surface-700">
            <th scope="col" class="px-3 py-2.5 text-left font-semibold">N.° guía</th>
            <th scope="col" class="px-3 py-2.5 text-left font-semibold">Fecha</th>
            <th scope="col" class="px-3 py-2.5 text-left font-semibold">Resistencia</th>
            <th scope="col" class="px-3 py-2.5 text-right font-semibold">Volumen m³</th>
            <th scope="col" class="px-3 py-2.5 text-right font-semibold">Vaciado m³</th>
            <th scope="col" class="px-3 py-2.5 text-left font-semibold">Factura</th>
            <th scope="col" class="px-3 py-2.5 text-left font-semibold">Estado al cierre</th>
            <th scope="col" class="px-3 py-2.5 text-right font-semibold">Importe S/</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="g in reporte.guias" :key="g.id" class="border-b border-surface-100" data-guia>
            <td class="px-3 py-2">
              <span class="block font-medium whitespace-nowrap">{{ g.numero }}</span>
              <span class="block text-xs text-surface-500">{{ g.proveedor }}</span>
            </td>
            <td class="px-3 py-2 whitespace-nowrap">
              {{ fecha(g.fecha) }}
              <span v-if="!g.del_mes" class="block text-xs text-amber-700" data-mes-anterior
                >mes anterior</span
              >
            </td>
            <td class="px-3 py-2 whitespace-nowrap">{{ g.resistencia }}</td>
            <td :class="celda">{{ decimales(g.volumen_m3) }}</td>
            <td :class="celda">{{ decimales(g.vaciado_m3) }}</td>
            <td class="px-3 py-2 whitespace-nowrap">
              <template v-if="g.factura">
                {{ g.factura }}
                <span v-if="g.factura_fecha" class="block text-xs text-surface-500">{{
                  fecha(g.factura_fecha)
                }}</span>
              </template>
              <span v-else class="text-surface-400">—</span>
            </td>
            <td class="px-3 py-2">
              <Tag
                :value="g.estado === 'facturada' ? 'Facturada' : 'Pendiente'"
                :severity="g.estado === 'facturada' ? 'success' : 'warn'"
              />
            </td>
            <td :class="celda">
              {{ decimales(g.importe_soles) }}
              <span
                v-if="g.precio_unitario !== null"
                class="block text-xs font-normal text-surface-500"
                >{{ decimales(g.volumen_m3) }} × {{ decimales(g.precio_unitario) }}</span
              >
            </td>
          </tr>
          <tr v-if="!reporte.guias.length">
            <td colspan="8" class="p-6 text-center text-surface-500">
              No hay guías del mes ni pendientes de meses anteriores.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
