<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { menu } from '@/router/menu'

const auth = useAuthStore()

const accesos = computed(() =>
  menu.filter((item) => item.ruta !== '/' && (!item.roles || auth.puedeVer(...item.roles))),
)
</script>

<template>
  <section>
    <h2 class="text-2xl font-semibold tracking-tight text-surface-900">
      Hola, {{ auth.usuario?.nombres }}
    </h2>
    <p class="mt-1 text-surface-500">{{ auth.usuario?.rol.descripcion }}</p>

    <div class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <RouterLink
        v-for="item in accesos"
        :key="item.ruta"
        :to="item.ruta"
        class="group flex items-center gap-4 rounded-xl border border-surface-200 bg-white p-4 transition hover:border-primary-300 hover:shadow-sm"
      >
        <span
          class="flex size-10 items-center justify-center rounded-lg bg-primary-50 text-primary-700 group-hover:bg-primary-100"
        >
          <i :class="item.icono" aria-hidden="true" />
        </span>
        <span class="font-medium text-surface-800">{{ item.etiqueta }}</span>
      </RouterLink>
    </div>
  </section>
</template>
