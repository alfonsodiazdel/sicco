<script setup lang="ts">
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import type { GuiaDocumento, TipoDocumento } from '@/types/documentos'
import type { Proveedor } from '@/types/proveedor'

type Estado = GuiaDocumento['estado_asignacion']
type Opcion = { valor: Estado; etiqueta: string }

defineProps<{ proveedores: Proveedor[]; estados: Opcion[]; rangoInvalido: boolean }>()
const emit = defineEmits<{ buscar: [] }>()
const tipo = defineModel<TipoDocumento>('tipo', { required: true })
const desde = defineModel<Date | null>('desde', { required: true })
const hasta = defineModel<Date | null>('hasta', { required: true })
const proveedorId = defineModel<number | null>('proveedorId', { required: true })
const estado = defineModel<Estado | null>('estado', { required: true })

const tipos = [
  { valor: 'guias', etiqueta: 'Guías' },
  { valor: 'facturas', etiqueta: 'Facturas' },
]
const comun = { showClear: true, fluid: true }
const fecha = { ...comun, dateFormat: 'dd/mm/yy', placeholder: 'dd/mm/aaaa' }
const conAviso = { pcInputText: { root: { 'aria-describedby': 'hasta-error' } } }
const fechaFinal = { ...fecha, pt: conAviso }
const lista = { ...comun, optionLabel: 'etiqueta', optionValue: 'valor', placeholder: 'Todos' }
const proveedor = { ...lista, optionLabel: 'razon_social', optionValue: 'id' }
</script>

<template>
  <form
    class="flex w-full flex-col gap-3 lg:flex-row lg:items-end"
    @submit.prevent="emit('buscar')"
  >
    <SelectButton
      v-model="tipo"
      :options="tipos"
      option-label="etiqueta"
      option-value="valor"
      :allow-empty="false"
      aria-label="Tipo de documento"
      @update:model-value="estado = null"
    />
    <div class="flex flex-col gap-1 lg:w-40">
      <label for="desde" class="text-sm font-medium">Desde</label>
      <DatePicker v-model="desde" input-id="desde" v-bind="fecha" />
    </div>
    <div class="flex flex-col gap-1 lg:w-40">
      <label for="hasta" class="text-sm font-medium">Hasta</label>
      <DatePicker v-model="hasta" input-id="hasta" v-bind="fechaFinal" :invalid="rangoInvalido" />
    </div>
    <div class="flex flex-col gap-1 lg:w-56">
      <label id="proveedor" class="text-sm font-medium">Proveedor</label>
      <Select
        v-model="proveedorId"
        label-id="proveedor"
        :options="proveedores"
        v-bind="proveedor"
      />
    </div>
    <div v-if="tipo === 'guias'" class="flex flex-col gap-1 lg:w-40">
      <label id="estado" class="text-sm font-medium">Estado</label>
      <Select v-model="estado" label-id="estado" :options="estados" v-bind="lista" />
    </div>
    <Button type="submit" label="Buscar" icon="pi pi-search" :disabled="rangoInvalido" />
    <p v-if="rangoInvalido" id="hasta-error" class="text-sm text-red-600 lg:self-center">
      La fecha final debe ser igual o posterior a la inicial
    </p>
  </form>
</template>
