<script setup lang="ts">
import Tooltip from 'primevue/tooltip'
import { decimales, soles } from '@/services/presupuesto'
import type { FilaAparteEat, FilaEat, MontosEat, ReporteEat } from '@/types/reportes'

defineProps<{ reporte: ReporteEat }>()

// El `title` nativo tarda en aparecer y a veces no aparece; el de PrimeVue sale al pasar el mouse
// y al llegar con el teclado. Igual que en TablaPresupuesto.
const vTooltip = Tooltip

// El PU proyectado solo lo traen las partidas; en subtotales, total y filas aparte sale «—».
type Campo = Exclude<keyof MontosEat, 'sobrecosto'> | 'pu_proyectado'

const ayudaElemento = "Partida del presupuesto: elemento y f'c. Debajo, su ítem."

// Las mismas columnas que el Excel y el PDF. La variación va en rojo cuando hay sobrecosto.
const columnas: { campo: Campo; encabezado: string; enSoles: boolean; ayuda: string }[] = [
  {
    campo: 'presupuesto_m3',
    encabezado: 'Presup. m³',
    enSoles: false,
    ayuda: 'Metrado de la partida con su desperdicio: metrado × (1 + desperdicio).',
  },
  {
    campo: 'presupuesto_soles',
    encabezado: 'Presup. S/',
    enSoles: true,
    ayuda:
      'Presupuesto meta: presup. m³ × precio unitario de la partida. Es el mismo total del presupuesto.',
  },
  {
    campo: 'consumido_m3',
    encabezado: 'Consumido m³',
    enSoles: false,
    ayuda:
      'Concreto de las guías hasta la fecha de corte asignado a los sectores del elemento, culminados o abiertos.',
  },
  {
    campo: 'consumido_soles',
    encabezado: 'Consumido S/',
    enSoles: true,
    ayuda:
      "Cada guía al precio de su factura; sin factura, al último precio facturado de su f'c o, si no hay, al de la partida.",
  },
  {
    campo: 'saldo_soles',
    encabezado: 'Saldo S/',
    enSoles: true,
    ayuda: 'Lo que queda del presupuesto a la fecha: presup. S/ − consumido S/.',
  },
  {
    campo: 'real_soles',
    encabezado: 'Real culminado S/',
    enSoles: true,
    ayuda: 'Costo de lo vaciado en los sectores culminados hasta la fecha de corte. Ya no cambia.',
  },
  {
    campo: 'proyectado_m3',
    encabezado: 'Proyectado m³',
    enSoles: false,
    ayuda: 'Lo que falta vaciar, con desperdicio: sectores abiertos y metrado aún sin sector.',
  },
  {
    campo: 'pu_proyectado',
    encabezado: 'PU proyectado S/',
    enSoles: true,
    ayuda:
      "Precio vigente por m³ de lo que falta: el último facturado de su f'c hasta el corte o, si no hay, el de la partida. ▲: sobre el precio del presupuesto.",
  },
  {
    campo: 'proyectado_soles',
    encabezado: 'Proyectado S/',
    enSoles: true,
    ayuda: 'Proyectado m³ × PU proyectado: lo que falta al precio vigente.',
  },
  {
    campo: 'eat_soles',
    encabezado: 'EAT S/',
    enSoles: true,
    ayuda: 'Costo estimado al término de la obra: real culminado + proyectado.',
  },
  {
    campo: 'variacion_soles',
    encabezado: 'Variación S/',
    enSoles: true,
    ayuda: 'Presupuesto − EAT. Negativa (en rojo) es sobrecosto; positiva, ahorro.',
  },
]

// Los cargos no tienen volumen y las guías sin sector no tienen EAT: esos montos llegan nulos. El PU
// proyectado no llega fuera de las partidas.
function monto(fila: MontosEat | FilaAparteEat, columna: (typeof columnas)[number]) {
  const valor = (fila as Partial<Record<Campo, number | null>>)[columna.campo]
  if (valor === null || valor === undefined) return '—'
  return columna.enSoles ? soles(valor) : decimales(valor)
}

// El PU proyectado de una partida: de dónde salió y si sube sobre el del presupuesto.
const subePu = (fila: FilaEat) => fila.pu_proyectado > fila.pu_presupuesto
function ayudaPu(fila: FilaEat) {
  const origen =
    fila.pu_origen === 'facturado'
      ? "Último precio facturado de su f'c hasta el corte."
      : "Precio de la partida: aún no hay facturas de su f'c hasta el corte."
  const comparacion = subePu(fila)
    ? `Sobre el del presupuesto, ${soles(fila.pu_presupuesto)}.`
    : `Presupuesto: ${soles(fila.pu_presupuesto)}.`
  return `${origen} ${comparacion}`
}

const rojo = (fila: { sobrecosto: boolean }) => ({ 'fila-sobrecosto': fila.sobrecosto })

const celda = 'px-3 py-2 text-right font-mono whitespace-nowrap tabular-nums'
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full border-collapse text-xs sm:text-sm">
      <thead>
        <tr class="border-b border-surface-200 bg-white text-surface-700">
          <th scope="col" class="px-3 py-2.5 text-left font-semibold">
            <abbr
              v-tooltip.top="ayudaElemento"
              :aria-label="`Elemento: ${ayudaElemento}`"
              tabindex="0"
              class="cursor-help underline decoration-dotted underline-offset-4"
              data-ayuda
              >Elemento</abbr
            >
          </th>
          <th
            v-for="columna in columnas"
            :key="columna.campo"
            scope="col"
            class="px-3 py-2.5 text-right font-semibold whitespace-nowrap"
          >
            <abbr
              v-tooltip.top="columna.ayuda"
              :aria-label="`${columna.encabezado}: ${columna.ayuda}`"
              tabindex="0"
              class="cursor-help underline decoration-dotted underline-offset-4"
              data-ayuda
              >{{ columna.encabezado }}</abbr
            >
          </th>
        </tr>
      </thead>

      <tbody v-for="grupo in reporte.grupos" :key="grupo.grupo_id">
        <tr class="border-b border-primary-100 bg-primary-50 text-primary-800">
          <th :colspan="columnas.length + 1" scope="rowgroup" class="px-3 py-2 text-left">
            <i class="pi pi-folder-open mr-1.5 text-xs" aria-hidden="true" />
            <span class="font-bold uppercase" data-grupo-nombre>{{ grupo.nombre }}</span>
          </th>
        </tr>

        <tr
          v-for="fila in grupo.filas"
          :key="fila.partida_id"
          class="border-b border-surface-200 bg-white"
          :class="rojo(fila)"
          data-fila
        >
          <td class="px-3 py-2 sm:min-w-56">
            <span class="block">{{ fila.etiqueta }}</span>
            <span v-if="fila.item" class="block text-xs text-surface-500"
              >Ítem {{ fila.item }}</span
            >
          </td>
          <td v-for="columna in columnas" :key="columna.campo" :class="celda">
            <span
              v-if="columna.campo === 'pu_proyectado'"
              v-tooltip.top="ayudaPu(fila)"
              :aria-label="`${monto(fila, columna)}. ${ayudaPu(fila)}`"
              tabindex="0"
              class="cursor-help"
              data-pu
              >{{ monto(fila, columna)
              }}<span
                v-if="subePu(fila)"
                class="ml-1 text-amber-600"
                aria-hidden="true"
                data-pu-sube
                >▲</span
              ></span
            >
            <template v-else>{{ monto(fila, columna) }}</template>
          </td>
        </tr>

        <tr
          class="border-b border-surface-200 bg-surface-50 font-semibold text-surface-700"
          :class="rojo(grupo.subtotal)"
          data-subtotal
        >
          <td class="px-3 py-2 text-right">Subtotal {{ grupo.nombre }}</td>
          <td v-for="columna in columnas" :key="columna.campo" :class="celda">
            {{ monto(grupo.subtotal, columna) }}
          </td>
        </tr>
      </tbody>

      <tbody v-if="reporte.filas_aparte.length">
        <tr class="border-b border-surface-200 bg-surface-100 text-surface-700">
          <th :colspan="columnas.length + 1" scope="rowgroup" class="px-3 py-2 text-left">
            <span class="font-bold uppercase">Fuera de los elementos</span>
          </th>
        </tr>
        <tr
          v-for="fila in reporte.filas_aparte"
          :key="`${fila.tipo}-${fila.concepto}`"
          class="border-b border-surface-200 bg-white"
          :class="rojo(fila)"
          data-fila-aparte
        >
          <td class="px-3 py-2">
            <span class="block">{{ fila.concepto }}</span>
            <small
              v-if="fila.aviso"
              class="flex items-center gap-1 text-xs text-amber-700"
              data-aviso
            >
              <i class="pi pi-exclamation-triangle text-xs" aria-hidden="true" />{{ fila.aviso }}
            </small>
          </td>
          <td v-for="columna in columnas" :key="columna.campo" :class="celda">
            {{ monto(fila, columna) }}
          </td>
        </tr>
      </tbody>

      <tfoot>
        <tr
          class="border-t-2 border-surface-300 bg-surface-100 font-semibold text-surface-900"
          :class="rojo(reporte.total)"
        >
          <td class="px-3 py-3 text-right uppercase">Total</td>
          <td v-for="columna in columnas" :key="columna.campo" :class="celda" class="py-3">
            {{ monto(reporte.total, columna) }}
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
</template>

<style scoped>
/* EAT por encima del presupuesto, en rojo. */
.fila-sobrecosto > td {
  background-color: var(--p-red-50);
  color: var(--p-red-800);
}
.fila-sobrecosto > td:first-child {
  box-shadow: inset 3px 0 0 var(--p-red-500);
}

@media (width < 40rem) {
  th,
  td {
    padding-inline: 0.5rem;
  }
}
</style>
