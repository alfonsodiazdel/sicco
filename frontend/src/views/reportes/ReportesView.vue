<script setup lang="ts">
import EncabezadoPagina from '@/components/organismos/EncabezadoPagina.vue'

const reportes = [
  {
    titulo: 'Reporte EAT',
    descripcion: 'Presupuesto, consumido, saldo y estimado a término por elemento, a una fecha.',
    icono: 'pi pi-chart-line',
    ruta: '/reportes/eat',
  },
  {
    titulo: 'Corte de mes',
    descripcion: 'Recibido, vaciado, facturado y pendiente de facturar al cierre de un mes.',
    icono: 'pi pi-calendar',
    ruta: '/reportes/corte-mes',
  },
]
</script>

<template>
  <section class="space-y-4">
    <EncabezadoPagina titulo="Reportes" subtitulo="Reportes del concreto de sus proyectos" />

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <RouterLink
        v-for="reporte in reportes"
        :key="reporte.ruta"
        :to="reporte.ruta"
        class="group flex items-start gap-4 rounded-xl border border-surface-200 bg-white p-4 transition hover:border-primary-300 hover:shadow-sm"
      >
        <span
          class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 group-hover:bg-primary-100"
        >
          <i :class="reporte.icono" aria-hidden="true" />
        </span>
        <span>
          <span class="block font-medium text-surface-800">{{ reporte.titulo }}</span>
          <span class="mt-0.5 block text-sm text-surface-500">{{ reporte.descripcion }}</span>
        </span>
      </RouterLink>
    </div>
  </section>
</template>
