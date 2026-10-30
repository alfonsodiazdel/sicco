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
}>()

defineSlots<{
  [columna: `col-${string}`]: (props: { fila: T }) => unknown
  acciones?: (props: { fila: T }) => unknown
}>()
</script>

<template>
  <DataTable
    :value="filas"
    :data-key="clave"
    :loading="cargando"
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
      :header-class="{
        'hidden sm:table-cell': columna.secundaria,
        '[&>div]:justify-end': columna.numerica,
      }"
      :body-class="{
        'hidden sm:table-cell': columna.secundaria,
        'text-right whitespace-nowrap': columna.numerica,
      }"
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
