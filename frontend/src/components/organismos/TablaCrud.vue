<script setup lang="ts" generic="T extends object">
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'

defineProps<{
  filas: T[]
  /**
   * `secundaria` oculta la columna por debajo de 640 px para que entren las acciones.
   * `numerica` la alinea a la derecha, sin partir el valor.
   */
  columnas: { campo: string; encabezado: string; secundaria?: boolean; numerica?: boolean }[]
  clave: string
  vacio: string
  cargando?: boolean
  /** Clase de cada fila, p. ej. para resaltar las que requieren atención. */
  claseFila?: (fila: T) => string | undefined
}>()

defineSlots<{
  [columna: `col-${string}`]: (props: { fila: T }) => unknown
  acciones?: (props: { fila: T }) => unknown
}>()

// Column declara header-class y body-class como String: un objeto funciona, pero Vue avisa en cada celda.
const clases = (columna: { secundaria?: boolean; numerica?: boolean }, deNumerica: string) =>
  [columna.secundaria && 'hidden sm:table-cell', columna.numerica && deNumerica]
    .filter(Boolean)
    .join(' ')
</script>

<template>
  <DataTable
    :value="filas"
    :data-key="clave"
    :loading="cargando"
    :row-class="claseFila"
    scrollable
    table-class="sm:min-w-[40rem]"
  >
    <template #empty>
      <p class="py-6 text-center text-surface-500">{{ vacio }}</p>
    </template>
    <Column
      v-for="columna in columnas"
      :key="columna.campo"
      :field="columna.campo"
      :header="columna.encabezado"
      :header-class="clases(columna, '[&>div]:justify-end')"
      :body-class="clases(columna, 'text-right whitespace-nowrap')"
    >
      <template v-if="$slots[`col-${columna.campo}`]" #body="{ data }">
        <slot :name="`col-${columna.campo}`" :fila="data" />
      </template>
    </Column>
    <Column v-if="$slots.acciones" class="w-px" frozen align-frozen="right">
      <template #header>
        <span class="sr-only">Acciones</span>
      </template>
      <template #body="{ data }">
        <div class="flex justify-end gap-1">
          <slot name="acciones" :fila="data" />
        </div>
      </template>
    </Column>
  </DataTable>
</template>

<style scoped>
@media (width < 40rem) {
  :deep(.p-datatable-thead > tr > th),
  :deep(.p-datatable-tbody > tr > td) {
    padding-inline: 0.5rem;
  }
}
</style>
