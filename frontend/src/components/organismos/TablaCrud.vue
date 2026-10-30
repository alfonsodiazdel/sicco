<script setup lang="ts" generic="T extends object">
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'

defineProps<{
  filas: T[]
  columnas: { campo: string; encabezado: string }[]
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
    table-style="min-width: 40rem"
  >
    <template #empty>
      <p class="py-6 text-center text-surface-500">{{ vacio }}</p>
    </template>
    <Column
      v-for="columna in columnas"
      :key="columna.campo"
      :field="columna.campo"
      :header="columna.encabezado"
    >
      <template v-if="$slots[`col-${columna.campo}`]" #body="{ data }">
        <slot :name="`col-${columna.campo}`" :fila="data" />
      </template>
    </Column>
    <Column v-if="$slots.acciones" class="w-32">
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
