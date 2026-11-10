<script setup lang="ts">
import { decimales, soles } from '@/services/presupuesto'
import type { FilaAparteEat, MontosEat, ReporteEat } from '@/types/reportes'

defineProps<{ reporte: ReporteEat }>()

type Campo = Exclude<keyof MontosEat, 'sobrecosto'>

const columnas: { campo: Campo; encabezado: string; enSoles: boolean }[] = [
  { campo: 'presupuesto_m3', encabezado: 'Presup. m³', enSoles: false },
  { campo: 'presupuesto_soles', encabezado: 'Presup. S/', enSoles: true },
  { campo: 'consumido_m3', encabezado: 'Consumido m³', enSoles: false },
  { campo: 'consumido_soles', encabezado: 'Consumido S/', enSoles: true },
  { campo: 'saldo_m3', encabezado: 'Saldo m³', enSoles: false },
  { campo: 'saldo_soles', encabezado: 'Saldo S/', enSoles: true },
  { campo: 'eat_m3', encabezado: 'EAT m³', enSoles: false },
  { campo: 'eat_soles', encabezado: 'EAT S/', enSoles: true },
]

// Los cargos de factura no tienen volumen: sus m³ llegan nulos.
function monto(fila: MontosEat | FilaAparteEat, columna: (typeof columnas)[number]) {
  const valor = fila[columna.campo]
  if (valor === null) return '—'
  return columna.enSoles ? soles(valor) : decimales(valor)
}

const rojo = (fila: { sobrecosto: boolean }) => ({ 'fila-sobrecosto': fila.sobrecosto })

const celda = 'px-3 py-2 text-right font-mono whitespace-nowrap tabular-nums'
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full border-collapse text-xs sm:text-sm">
      <thead>
        <tr class="border-b border-surface-200 bg-white text-surface-700">
          <th scope="col" class="px-3 py-2.5 text-left font-semibold">Elemento</th>
          <th
            v-for="columna in columnas"
            :key="columna.campo"
            scope="col"
            class="px-3 py-2.5 text-right font-semibold whitespace-nowrap"
          >
            {{ columna.encabezado }}
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
            {{ monto(fila, columna) }}
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
