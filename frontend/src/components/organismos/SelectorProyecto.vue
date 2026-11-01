<script setup lang="ts">
import { storeToRefs } from 'pinia'
import Select from 'primevue/select'
import { useProyectoActualStore } from '@/stores/proyectoActual'

const proyectoActual = useProyectoActualStore()
const { proyectos, proyectoId, proyecto, cargado, cargando } = storeToRefs(proyectoActual)
</script>

<template>
  <div
    v-if="cargando"
    class="h-8 w-24 animate-pulse rounded-md bg-surface-100 sm:w-56"
    aria-hidden="true"
  />
  <template v-else-if="cargado">
    <p v-if="!proyecto" class="text-sm text-surface-500">Sin proyectos asignados</p>

    <!-- Con un solo proyecto no hay nada que elegir. -->
    <p
      v-else-if="proyectos.length === 1"
      class="flex min-w-0 items-center gap-2 text-sm font-medium text-surface-700"
      :title="proyecto.nombre"
      data-proyecto-actual
    >
      <i class="pi pi-building text-surface-500" aria-hidden="true" />
      <span class="sm:hidden">{{ proyecto.codigo }}</span>
      <span class="hidden max-w-56 truncate sm:inline">{{ proyecto.nombre }}</span>
    </p>

    <!-- En celular solo el código: el encabezado también lleva el título y el perfil. -->
    <Select
      v-else
      :model-value="proyectoId"
      :options="proyectos"
      option-label="nombre"
      option-value="id"
      aria-label="Proyecto"
      size="small"
      class="w-32 sm:w-64"
      @update:model-value="proyectoActual.elegir"
    >
      <template #value>
        <span class="flex min-w-0 items-center gap-2" :title="proyecto?.nombre">
          <i class="pi pi-building hidden text-surface-500 sm:inline" aria-hidden="true" />
          <span class="sm:hidden">{{ proyecto?.codigo }}</span>
          <span class="hidden truncate sm:inline">{{ proyecto?.nombre }}</span>
        </span>
      </template>
      <template #option="{ option }">
        <span class="mr-2 text-surface-500">{{ option.codigo }}</span>
        {{ option.nombre }}
      </template>
    </Select>
  </template>
</template>
