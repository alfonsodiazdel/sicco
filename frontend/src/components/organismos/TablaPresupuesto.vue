<script setup lang="ts">
import { computed } from 'vue'
import Tooltip from 'primevue/tooltip'
import { fcEnKg } from '@/catalogos/partidas'
import {
  agruparPartidas,
  decimales,
  factorDesperdicio,
  soles,
  totalPartida,
  totalPresupuesto,
  volumenPresupuestado,
} from '@/services/presupuesto'
import type { GrupoFila } from '@/types/grupos'
import type { PartidaFila } from '@/types/partidas'

const props = defineProps<{
  partidas: PartidaFila[]
  grupos: GrupoFila[]
  vacio: string
  cargando?: boolean
}>()

// El `title` nativo tarda en aparecer y a veces no aparece; el de PrimeVue sale al pasar el mouse.
const vTooltip = Tooltip

const slots = defineSlots<{ acciones?: (props: { fila: PartidaFila }) => unknown }>()

const agrupadas = computed(() => agruparPartidas(props.partidas, props.grupos))

const metradoTotal = computed(
  () => agrupadas.value.reduce((suma, g) => suma + Math.round(g.metrado_m3 * 100), 0) / 100,
)
const presupuestadoTotal = computed(
  () => agrupadas.value.reduce((suma, g) => suma + Math.round(g.volumen_presup_m3 * 100), 0) / 100,
)
const total = computed(() => totalPresupuesto(props.partidas))

// Las columnas de identificación y de cálculo se ocultan en pantallas angostas; en celular quedan
// la descripción, el metrado neto y el parcial.
const deIdentificacion = 'hidden lg:table-cell'
const deCalculo = 'hidden sm:table-cell'

const columnas = computed(() => [
  { encabezado: 'P. control', titulo: 'Partida de control', clase: deIdentificacion },
  { encabezado: 'Ítem', titulo: 'Ítem del expediente técnico', clase: deIdentificacion },
  { encabezado: 'Descripción', clase: 'text-left' },
  { encabezado: 'Und', clase: `${deIdentificacion} text-center` },
  { encabezado: "f'c", clase: `${deCalculo} text-center` },
  { encabezado: 'm³ neto', clase: 'text-right' },
  { encabezado: 'Desp.', clase: `${deCalculo} text-right` },
  { encabezado: 'm³ presup.', clase: `${deCalculo} text-right` },
  { encabezado: 'P.U.', clase: `${deCalculo} text-right` },
  { encabezado: 'Parcial', clase: 'text-right' },
  ...(slots.acciones ? [{ encabezado: 'Acciones', clase: 'w-px' }] : []),
])

// Las filas de grupo y de mensaje ocupan todo el ancho.
const columnasTotal = computed(() => columnas.value.length)

const entero = (valor: number) =>
  `S/ ${valor.toLocaleString('es-PE', { maximumFractionDigits: 0 })}`

const resumen = (cantidad: number, metrado: number, importe: number) =>
  `${cantidad} ${cantidad === 1 ? 'partida' : 'partidas'} · ${decimales(metrado)} m³ neto · ${entero(importe)}`
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full border-collapse text-xs sm:text-sm">
      <thead>
        <tr class="border-b border-surface-200 bg-white text-surface-700">
          <th
            v-for="columna in columnas"
            :key="columna.encabezado"
            scope="col"
            class="px-3 py-2.5 font-semibold whitespace-nowrap"
            :class="columna.clase"
          >
            <span v-if="columna.encabezado === 'Acciones'" class="sr-only">Acciones</span>
            <abbr
              v-else-if="columna.titulo"
              v-tooltip.top="columna.titulo"
              :aria-label="columna.titulo"
              tabindex="0"
              class="cursor-help underline decoration-dotted underline-offset-4"
              >{{ columna.encabezado }}</abbr
            >
            <template v-else>{{ columna.encabezado }}</template>
          </th>
        </tr>
      </thead>

      <tbody v-if="cargando">
        <tr>
          <td :colspan="columnasTotal" class="py-6 text-center text-surface-500">
            <i class="pi pi-spin pi-spinner mr-2" aria-hidden="true" />Cargando…
          </td>
        </tr>
      </tbody>

      <tbody v-else-if="!agrupadas.length">
        <tr>
          <td :colspan="columnasTotal" class="py-6 text-center text-surface-500">{{ vacio }}</td>
        </tr>
      </tbody>

      <template v-else>
        <tbody v-for="g in agrupadas" :key="g.grupo.id" data-grupo>
          <tr class="border-b border-primary-100 bg-primary-50 text-primary-800">
            <th :colspan="columnasTotal" scope="rowgroup" class="px-3 py-2 text-left font-normal">
              <i class="pi pi-folder-open mr-1.5 text-xs" aria-hidden="true" />
              <span class="font-bold uppercase" data-grupo-nombre>{{ g.grupo.nombre }}</span>
              <span class="text-xs text-primary-700">
                · {{ resumen(g.partidas.length, g.metrado_m3, g.total) }}</span
              >
            </th>
          </tr>

          <tr
            v-for="fila in g.partidas"
            :key="fila.id"
            class="border-b border-surface-200 bg-white hover:bg-surface-50"
            data-partida
          >
            <td :class="deIdentificacion" class="px-3 py-2 whitespace-nowrap">
              {{ g.grupo.partida_control }}
            </td>
            <td :class="deIdentificacion" class="px-3 py-2 whitespace-nowrap">
              {{ fila.item ?? '' }}
            </td>
            <td class="px-3 py-2 sm:min-w-56">
              <span class="block">{{ fila.descripcion }}</span>
              <span class="block text-xs text-surface-500">{{ fila.elemento }}</span>
            </td>
            <td :class="deIdentificacion" class="px-3 py-2 text-center">m3</td>
            <td :class="deCalculo" class="px-3 py-2 text-center">
              {{ fcEnKg(fila.resistencia_fc) }}
            </td>
            <td class="px-3 py-2 text-right font-mono whitespace-nowrap tabular-nums">
              {{ decimales(fila.metrado_m3) }}
            </td>
            <td
              :class="deCalculo"
              class="px-3 py-2 text-right font-mono whitespace-nowrap tabular-nums"
              :title="`${decimales(fila.factor_desperdicio)} % de desperdicio`"
            >
              {{ factorDesperdicio(fila.factor_desperdicio) }}
            </td>
            <td
              :class="deCalculo"
              class="px-3 py-2 text-right font-mono whitespace-nowrap tabular-nums"
            >
              {{ decimales(volumenPresupuestado(fila)) }}
            </td>
            <td
              :class="deCalculo"
              class="px-3 py-2 text-right font-mono whitespace-nowrap tabular-nums"
            >
              {{ decimales(fila.precio_unitario) }}
            </td>
            <td class="px-3 py-2 text-right font-mono font-semibold whitespace-nowrap tabular-nums">
              {{ decimales(totalPartida(fila)) }}
            </td>
            <td v-if="slots.acciones" class="acciones px-2 py-1">
              <div class="flex justify-end gap-1">
                <slot name="acciones" :fila="fila" />
              </div>
            </td>
          </tr>

          <tr
            class="border-b border-surface-200 bg-surface-50 font-semibold text-surface-700"
            data-subtotal
          >
            <td :class="deIdentificacion" />
            <td :class="deIdentificacion" />
            <td class="px-3 py-2 text-right">Subtotal {{ g.grupo.nombre }}:</td>
            <td :class="deIdentificacion" />
            <td :class="deCalculo" />
            <td class="px-3 py-2 text-right font-mono whitespace-nowrap tabular-nums">
              {{ decimales(g.metrado_m3) }}
            </td>
            <td :class="deCalculo" />
            <td
              :class="deCalculo"
              class="px-3 py-2 text-right font-mono whitespace-nowrap tabular-nums"
            >
              {{ decimales(g.volumen_presup_m3) }}
            </td>
            <td :class="deCalculo" />
            <td class="px-3 py-2 text-right font-mono whitespace-nowrap tabular-nums">
              {{ soles(g.total) }}
            </td>
            <td v-if="slots.acciones" />
          </tr>
        </tbody>
      </template>

      <tfoot>
        <tr class="border-t-2 border-surface-300 bg-surface-100 font-semibold text-surface-900">
          <td :class="deIdentificacion" />
          <td :class="deIdentificacion" />
          <td class="px-3 py-3 text-right uppercase">Total presupuestado:</td>
          <td :class="deIdentificacion" />
          <td :class="deCalculo" />
          <td class="px-3 py-3 text-right font-mono whitespace-nowrap tabular-nums">
            {{ decimales(metradoTotal) }}
          </td>
          <td :class="deCalculo" />
          <td
            :class="deCalculo"
            class="px-3 py-3 text-right font-mono whitespace-nowrap tabular-nums"
          >
            {{ decimales(presupuestadoTotal) }}
          </td>
          <td :class="deCalculo" />
          <td
            class="px-3 py-3 text-right font-mono whitespace-nowrap tabular-nums"
            data-total-presupuesto
          >
            {{ soles(total) }}
          </td>
          <td v-if="slots.acciones" />
        </tr>
      </tfoot>
    </table>
  </div>
</template>

<style scoped>
@media (width < 40rem) {
  th,
  td {
    padding-inline: 0.5rem;
  }

  td.acciones {
    padding-inline: 0;
  }
}
</style>
