<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import Menu from 'primevue/menu'
import Avatar from 'primevue/avatar'
import OverlayBadge from 'primevue/overlaybadge'
import { useToast } from 'primevue/usetoast'
import SelectorProyecto from '@/components/organismos/SelectorProyecto.vue'
import { useAuthStore } from '@/stores/auth'
import { useAlertasStore } from '@/stores/alertas'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import { menu } from '@/router/menu'

const CLAVE_MENU_CONTRAIDO = 'sicco-menu-contraido'

const auth = useAuthStore()
const alertas = useAlertasStore()
const proyectoActual = useProyectoActualStore()
const router = useRouter()
const route = useRoute()
const toast = useToast()

const consultaEscritorio = window.matchMedia('(min-width: 768px)')
const escritorio = ref(consultaEscritorio.matches)
const alCambiarPantalla = (e: MediaQueryListEvent) => (escritorio.value = e.matches)
consultaEscritorio.addEventListener('change', alCambiarPantalla)
onBeforeUnmount(() => consultaEscritorio.removeEventListener('change', alCambiarPantalla))

const menuContraido = ref(localStorage.getItem(CLAVE_MENU_CONTRAIDO) === '1')
const menuMovilAbierto = ref(false)
const menuUsuario = ref<InstanceType<typeof Menu> | null>(null)

const opciones = computed(() => menu.filter((item) => !item.roles || auth.puedeVer(...item.roles)))

const menuVisible = computed(() =>
  escritorio.value ? !menuContraido.value : menuMovilAbierto.value,
)

const iniciales = computed(() => {
  const u = auth.usuario
  return u ? `${u.nombres[0] ?? ''}${u.apellidos[0] ?? ''}`.toUpperCase() : ''
})

const accionesUsuario = [
  {
    label: 'Cambiar contraseña',
    icon: 'pi pi-key',
    command: () => router.push({ name: 'cambiar-password' }),
  },
  { separator: true },
  { label: 'Cerrar sesión', icon: 'pi pi-sign-out', command: cerrarSesion },
]

function alternarMenu() {
  if (escritorio.value) menuContraido.value = !menuContraido.value
  else menuMovilAbierto.value = !menuMovilAbierto.value
}

async function cerrarSesion() {
  await auth.logout()
  await router.replace({ name: 'login' })
}

function activo(ruta: string) {
  return ruta === '/' ? route.path === '/' : route.path.startsWith(ruta)
}

watch(menuContraido, (contraido) =>
  localStorage.setItem(CLAVE_MENU_CONTRAIDO, contraido ? '1' : '0'),
)

watch([() => route.fullPath, escritorio], () => (menuMovilAbierto.value = false))

// CU14 paso 4: el ícono de notificaciones muestra las alertas pendientes. Se refresca al navegar
// y cada minuto, para enterarse de las que registran otros usuarios.
const MINUTO = 60_000
watch(() => route.path, alertas.actualizar, { immediate: true })
const intervaloAlertas = window.setInterval(alertas.actualizar, MINUTO)
onBeforeUnmount(() => window.clearInterval(intervaloAlertas))
const etiquetaAlertas = computed(() =>
  alertas.pendientes === 1 ? '1 alerta pendiente' : `${alertas.pendientes} alertas pendientes`,
)

// Un enlace con ?proyecto=<id> elige ese proyecto en el encabezado. Si no es uno de los activos
// asignados se avisa en vez de mostrar otro en silencio; luego se quita de la URL, que ya no
// seguiría al selector.
watch(
  () => [route.meta.porProyecto, route.query.proyecto],
  async () => {
    if (!route.meta.porProyecto) return
    try {
      await proyectoActual.cargar()
    } catch {
      return // La vista ya avisa que no se pudieron traer los proyectos.
    }
    const { proyecto: pedido, ...resto } = route.query
    if (pedido === undefined) return
    if (!proyectoActual.elegir(pedido)) {
      toast.add({
        severity: 'warn',
        summary: 'No tiene acceso a ese proyecto o ya no está activo.',
        detail: proyectoActual.proyecto
          ? `Se muestra ${proyectoActual.proyecto.nombre}.`
          : undefined,
        life: 6000,
      })
    }
    await router.replace({ query: resto })
  },
  { immediate: true },
)
</script>

<template>
  <div class="flex min-h-dvh">
    <!-- Barra lateral (escritorio) -->
    <aside
      class="sticky top-0 hidden h-dvh shrink-0 flex-col overflow-hidden border-r border-surface-200 bg-white transition-[width] duration-200 md:flex"
      :class="menuContraido ? 'w-16' : 'w-60'"
    >
      <div
        class="flex h-14 shrink-0 items-center gap-2.5 border-b border-surface-200"
        :class="menuContraido ? 'justify-center' : 'px-4'"
      >
        <div
          class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-contrast"
        >
          <i class="pi pi-box text-sm" aria-hidden="true" />
        </div>
        <div v-if="!menuContraido" class="min-w-0">
          <span class="block font-semibold leading-tight tracking-tight text-surface-900"
            >SICCO</span
          >
          <span class="block text-xs leading-tight text-surface-500"
            >Control de concreto premezclado</span
          >
        </div>
      </div>
      <nav class="flex-1 space-y-0.5 overflow-y-auto p-3" aria-label="Menú principal">
        <RouterLink
          v-for="item in opciones"
          :key="item.ruta"
          :to="item.ruta"
          :title="menuContraido ? item.etiqueta : undefined"
          class="flex items-center gap-3 rounded-lg py-2 text-sm transition-colors"
          :class="[
            menuContraido ? 'justify-center' : 'px-3',
            activo(item.ruta)
              ? 'bg-primary-50 font-medium text-primary-700'
              : 'text-surface-600 hover:bg-surface-100 hover:text-surface-900',
          ]"
        >
          <i :class="item.icono" class="text-base" aria-hidden="true" />
          <span class="whitespace-nowrap" :class="{ 'sr-only': menuContraido }">{{
            item.etiqueta
          }}</span>
        </RouterLink>
      </nav>
    </aside>

    <!-- Menú móvil (almacenero en obra) -->
    <Drawer v-model:visible="menuMovilAbierto" header="SICCO" class="!w-72">
      <nav class="space-y-0.5" aria-label="Menú principal">
        <RouterLink
          v-for="item in opciones"
          :key="item.ruta"
          :to="item.ruta"
          class="flex items-center gap-3 rounded-lg px-3 py-3 text-base"
          :class="
            activo(item.ruta) ? 'bg-primary-50 font-medium text-primary-700' : 'text-surface-700'
          "
        >
          <i :class="item.icono" aria-hidden="true" />
          {{ item.etiqueta }}
        </RouterLink>
      </nav>
    </Drawer>

    <div class="flex min-w-0 flex-1 flex-col">
      <header
        class="sticky top-0 z-10 flex h-14 items-center justify-between gap-3 border-b border-surface-200 bg-white/90 px-4 backdrop-blur md:px-6"
      >
        <div class="flex min-w-0 items-center gap-2">
          <Button
            icon="pi pi-bars"
            text
            rounded
            severity="secondary"
            aria-label="Mostrar u ocultar menú"
            title="Mostrar u ocultar menú"
            :aria-expanded="menuVisible"
            @click="alternarMenu"
          />
          <h1 class="truncate text-base font-semibold text-surface-900">{{ route.meta.titulo }}</h1>
        </div>

        <div class="flex shrink-0 items-center gap-2 sm:gap-4">
          <SelectorProyecto v-if="route.meta.porProyecto" />
          <RouterLink
            v-if="alertas.recibe"
            to="/alertas"
            class="flex size-10 items-center justify-center rounded-full text-surface-600 hover:bg-surface-100"
            :aria-label="`Alertas de consumo: ${etiquetaAlertas}`"
            :title="etiquetaAlertas"
            data-campana
          >
            <OverlayBadge
              v-if="alertas.pendientes"
              :value="alertas.pendientes > 99 ? '99+' : alertas.pendientes"
              severity="danger"
              size="small"
            >
              <i class="pi pi-bell text-lg" aria-hidden="true" />
            </OverlayBadge>
            <i v-else class="pi pi-bell text-lg" aria-hidden="true" />
          </RouterLink>
          <button
            type="button"
            class="flex items-center gap-3 rounded-lg px-2 py-1.5 text-left hover:bg-surface-100"
            aria-haspopup="true"
            aria-controls="menu-usuario"
            @click="menuUsuario?.toggle($event)"
          >
            <span class="hidden text-right sm:block">
              <span class="block text-sm font-medium leading-tight text-surface-900">
                {{ auth.usuario?.nombre_completo }}
              </span>
              <span class="block text-xs text-surface-500">{{ auth.usuario?.rol.nombre }}</span>
            </span>
            <Avatar :label="iniciales" shape="circle" class="!bg-primary-100 !text-primary-700" />
          </button>
        </div>
        <Menu id="menu-usuario" ref="menuUsuario" :model="accionesUsuario" popup />
      </header>

      <main class="flex-1 p-4 md:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
