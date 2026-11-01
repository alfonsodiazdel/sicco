import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { proyectosServicio } from '@/services/proyectos'
import { useAuthStore } from '@/stores/auth'
import type { ProyectoFila } from '@/types/proyectos'

const CLAVE_PROYECTO = 'sicco-proyecto'

/**
 * El proyecto en el que trabaja el usuario, elegido en el encabezado y compartido por todas las
 * vistas por proyecto. Solo ofrece los proyectos activos asignados (USUARIO_PROYECTO) y recuerda
 * el último elegido por usuario.
 */
export const useProyectoActualStore = defineStore('proyectoActual', () => {
  const auth = useAuthStore()

  const proyectos = ref<ProyectoFila[]>([])
  const proyectoId = ref<number | null>(null)
  /** true cuando ya se trajeron los proyectos del usuario de la sesión. */
  const cargado = ref(false)
  const cargando = ref(false)

  const proyecto = computed(() => proyectos.value.find((p) => p.id === proyectoId.value) ?? null)

  // Sin id (pruebas con un usuario a medias) no se guarda nada.
  const clave = () => (auth.usuario?.id ? `${CLAVE_PROYECTO}-${auth.usuario.id}` : null)

  let pendiente: Promise<void> | null = null
  // Cambia al cambiar de usuario: la respuesta pedida para el anterior ya no aplica.
  let version = 0

  async function traer() {
    const pedidaEn = version
    const activos = (await proyectosServicio.asignados()).filter((p) => p.estado === 'Activo')
    if (pedidaEn !== version) return
    const llave = clave()
    const recordado = proyectoId.value ?? Number(llave && localStorage.getItem(llave))
    proyectos.value = activos
    proyectoId.value = (activos.find((p) => p.id === recordado) ?? activos[0])?.id ?? null
    cargado.value = true
  }

  /** Trae los proyectos una sola vez aunque varias vistas lo pidan a la vez. */
  function cargar() {
    if (cargado.value) return Promise.resolve()
    if (!pendiente) {
      cargando.value = true
      pendiente = traer().finally(() => {
        pendiente = null
        cargando.value = false
      })
    }
    return pendiente
  }

  /** Tras crear o editar proyectos: el estado o el nombre pueden haber cambiado. */
  async function recargar() {
    await pendiente?.catch(() => undefined)
    cargado.value = false
    await cargar()
  }

  /** Elige un proyecto solo si está entre los ofrecidos; acepta el id como llega en la URL. */
  function elegir(id: unknown) {
    const elegido = proyectos.value.find((p) => String(p.id) === String(id))
    if (elegido) proyectoId.value = elegido.id
    return !!elegido
  }

  function limpiar() {
    version++
    pendiente = null
    proyectos.value = []
    proyectoId.value = null
    cargado.value = false
    cargando.value = false
  }

  watch(proyectoId, (id) => {
    const llave = clave()
    if (llave && id !== null) localStorage.setItem(llave, String(id))
  })

  watch(() => auth.usuario?.id, limpiar)

  return { proyectos, proyectoId, proyecto, cargado, cargando, cargar, recargar, elegir, limpiar }
})
