<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import DialogoFormulario from '@/components/organismos/DialogoFormulario.vue'
import BuscadorTabla from '@/components/moleculas/BuscadorTabla.vue'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'
import { nivelesServicio } from '@/services/niveles'
import { partidasServicio } from '@/services/partidas'
import { decimales, volumenDisponible } from '@/services/presupuesto'
import { esquemaNivel, valoresDeNivel } from '@/validaciones/nivel'
import { esquemaSector, valoresDeSector } from '@/validaciones/sector'
import type { NivelFila, SectorFila } from '@/types/niveles'
import type { PartidaFila } from '@/types/partidas'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'

const toast = useToast()
const auth = useAuthStore()
const proyectoActual = useProyectoActualStore()

// El administrador entra en modo consulta: niveles y sectores los gestiona el ingeniero de producción.
const puedeGestionar = computed(() => auth.tieneRol('ingeniero_produccion'))

// El proyecto se elige en el encabezado (SelectorProyecto).
const { proyecto, proyectoId } = storeToRefs(proyectoActual)
const partidas = ref<PartidaFila[]>([])
const niveles = ref<NivelFila[]>([])
const sectores = ref<SectorFila[]>([])
const nivelId = ref<number | null>(null)
const busqueda = ref('')
const cargando = ref(true)

const nivel = computed(() => niveles.value.find((n) => n.id === nivelId.value))
// Precondición de CU05: el proyecto tiene presupuesto registrado (CU04).
const conPresupuesto = computed(() => partidas.value.length > 0)

const nivelesOrdenados = computed(() =>
  [...niveles.value].sort((a, b) => a.orden - b.orden || a.id - b.id),
)

const etiquetaPartida = (p: PartidaFila) => `${p.elemento} · ${p.resistencia_fc}`
const opcionesPartida = computed(() =>
  partidas.value.map((p) => ({ id: p.id, etiqueta: etiquetaPartida(p) })),
)

const columnas = [
  { campo: 'nombre', encabezado: 'Sector' },
  { campo: 'elemento', encabezado: 'Elemento', secundaria: true },
  { campo: 'volumen_presup_m3', encabezado: 'Volumen presupuestado (m³)', numerica: true },
  { campo: 'volumen_vaciado_m3', encabezado: 'Vaciado (m³)', secundaria: true, numerica: true },
]

const filas = computed(() => {
  const texto = busqueda.value.trim().toLowerCase()
  return sectores.value
    .filter((s) => s.nivel_id === nivelId.value)
    .map((s) => {
      const partida = partidas.value.find((p) => p.id === s.presupuesto_id)
      return { ...s, elemento: partida ? etiquetaPartida(partida) : '—' }
    })
    .filter((s) => [s.nombre, s.elemento].some((v) => v.toLowerCase().includes(texto)))
})

async function cargar() {
  const id = proyectoId.value
  if (!id) return
  const [p, n, s] = await Promise.all([
    partidasServicio.listar(id),
    nivelesServicio.listarNiveles(id),
    nivelesServicio.listarSectores(id),
  ])
  // Si se eligió otro proyecto mientras cargaba, esta respuesta ya no aplica.
  if (id !== proyectoId.value) return
  partidas.value = p
  niveles.value = n
  sectores.value = s
  nivelId.value ??= nivelesOrdenados.value[0]?.id ?? null
}

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

// Al tener la lista de proyectos y cada vez que se elige otro en el encabezado.
watch(
  () => proyectoActual.cargado && proyectoId.value,
  async () => {
    if (!proyectoActual.cargado) return
    const id = proyectoId.value
    nivelId.value = null
    partidas.value = []
    niveles.value = []
    sectores.value = []
    cargando.value = true
    try {
      await cargar()
    } catch (error) {
      if (id === proyectoId.value) avisarError(error)
    } finally {
      if (id === proyectoId.value) cargando.value = false
    }
  },
  { immediate: true },
)

onMounted(async () => {
  try {
    await proyectoActual.cargar()
  } catch (error) {
    avisarError(error)
    cargando.value = false
  }
})

const dialogoNivel = ref(false)
const editandoNivel = ref<NivelFila | null>(null)

const formNivel = useForm({ validationSchema: toTypedSchema(esquemaNivel) })
const [nombreNivel, nombreNivelAttrs] = formNivel.defineField('nombre')
const [orden, ordenAttrs] = formNivel.defineField('orden')

function abrirNivel(n: NivelFila | null = null) {
  editandoNivel.value = n
  formNivel.resetForm({ values: n ? valoresDeNivel(n) : {} })
  dialogoNivel.value = true
}

const guardarNivel = formNivel.handleSubmit(async (valores) => {
  try {
    if (editandoNivel.value) {
      await nivelesServicio.actualizarNivel(editandoNivel.value.id, valores)
    } else {
      // Se elige el nivel nuevo para registrar enseguida sus sectores.
      nivelId.value = (await nivelesServicio.crearNivel(proyectoId.value!, valores)).id
    }
    toast.add({
      severity: 'success',
      summary: editandoNivel.value ? 'Cambios guardados.' : 'Registro creado.',
      life: 4000,
    })
    dialogoNivel.value = false
    await cargar()
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) formNivel.setErrors(campos)
    else avisarError(error)
  }
})

const dialogoSector = ref(false)
const editandoSector = ref<SectorFila | null>(null)

function disponible(presupuestoId: number) {
  const partida = partidas.value.find((p) => p.id === presupuestoId)
  return partida ? volumenDisponible(partida, sectores.value, editandoSector.value?.id) : 0
}

const notaDisponible = computed(
  () =>
    'Disponible por elemento: ' +
    partidas.value
      .map((p) => `${etiquetaPartida(p)}, ${decimales(disponible(p.id))} m³`)
      .join('; ') +
    '.',
)

const formSector = useForm({ validationSchema: toTypedSchema(esquemaSector(disponible)) })
const [nombreSector, nombreSectorAttrs] = formSector.defineField('nombre')
const [presupuestoId, presupuestoIdAttrs] = formSector.defineField('presupuesto_id')
const [volumen, volumenAttrs] = formSector.defineField('volumen_presup_m3')

function abrirSector(s: SectorFila | null = null) {
  // Flujo alternativo 1 de CU05: sin niveles, primero se registra uno.
  if (!s && !nivel.value) {
    toast.add({
      severity: 'warn',
      summary: 'Primero registre un nivel con «Nuevo nivel».',
      life: 5000,
    })
    return
  }
  editandoSector.value = s
  formSector.resetForm({ values: s ? valoresDeSector(s) : {} })
  dialogoSector.value = true
}

const guardarSector = formSector.handleSubmit(async (valores) => {
  try {
    if (editandoSector.value)
      await nivelesServicio.actualizarSector(editandoSector.value.id, valores)
    else await nivelesServicio.crearSector(nivelId.value!, valores)
    toast.add({
      severity: 'success',
      summary: editandoSector.value ? 'Cambios guardados.' : 'Sector registrado',
      life: 4000,
    })
    dialogoSector.value = false
    await cargar()
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) formSector.setErrors(campos)
    else avisarError(error)
  }
})

const entero = { locale: 'es-PE', maxFractionDigits: 0, useGrouping: false, min: 1, fluid: true }
const numero = { locale: 'es-PE', minFractionDigits: 0, maxFractionDigits: 2, min: 0, fluid: true }
</script>

<template>
  <PaginaListado
    :titulo="proyecto ? `Niveles y sectores – ${proyecto.nombre}` : 'Niveles y sectores'"
    subtitulo="Sectores de vaciado por nivel, cada uno enlazado a una partida del presupuesto"
  >
    <template v-if="puedeGestionar" #acciones>
      <Button
        label="Nuevo nivel"
        icon="pi pi-plus"
        aria-label="Nuevo nivel"
        outlined
        :disabled="!conPresupuesto"
        @click="abrirNivel()"
      />
    </template>

    <Message
      v-if="proyecto && !cargando && !conPresupuesto"
      severity="warn"
      icon="pi pi-exclamation-triangle"
      class="m-3"
    >
      Este proyecto aún no tiene presupuesto registrado. Oficina técnica debe registrar sus partidas
      antes de definir los sectores.
    </Message>

    <div class="grid grid-cols-1 md:grid-cols-3">
      <div class="border-b border-surface-200 md:border-r md:border-b-0">
        <h3 class="border-b border-surface-200 p-3 font-semibold">Niveles</h3>
        <ul aria-label="Niveles" class="space-y-1 p-2">
          <li
            v-for="n in nivelesOrdenados"
            :key="n.id"
            class="flex items-center rounded-md"
            :class="{ 'bg-primary-50 font-semibold text-primary-700': n.id === nivelId }"
          >
            <button
              type="button"
              class="flex-1 rounded-md px-3 py-2 text-left"
              :title="`Orden ${n.orden}`"
              :aria-current="n.id === nivelId"
              @click="nivelId = n.id"
            >
              {{ n.nombre }}
            </button>
            <Button
              v-if="puedeGestionar"
              icon="pi pi-pencil"
              text
              rounded
              :aria-label="`Editar nivel ${n.nombre}`"
              :title="`Editar nivel ${n.nombre}`"
              @click="abrirNivel(n)"
            />
          </li>
          <li v-if="!nivelesOrdenados.length" class="px-3 py-2 text-surface-500">
            Aún no hay niveles. Registre uno con «Nuevo nivel».
          </li>
        </ul>
      </div>

      <div class="min-w-0 md:col-span-2">
        <div
          class="flex flex-col gap-3 border-b border-surface-200 p-3 sm:flex-row sm:items-center sm:justify-between"
        >
          <h3 class="font-semibold">
            {{ nivel ? `Sectores de vaciado – ${nivel.nombre}` : 'Sectores de vaciado' }}
          </h3>
          <Button
            v-if="puedeGestionar"
            label="Nuevo sector"
            icon="pi pi-plus"
            aria-label="Nuevo sector"
            class="self-start"
            :disabled="!conPresupuesto"
            @click="abrirSector()"
          />
        </div>
        <div class="border-b border-surface-200 p-3">
          <BuscadorTabla
            v-model="busqueda"
            placeholder="Buscar por nombre o elemento"
            etiqueta="Buscar sectores"
            :largo="80"
          />
        </div>

        <TablaCrud
          :filas="filas"
          :columnas="columnas"
          clave="id"
          :vacio="
            nivel ? 'No hay sectores que coincidan.' : 'Registre un nivel para ver sus sectores.'
          "
          :cargando="cargando"
        >
          <template #col-nombre="{ fila }">
            <span>{{ fila.nombre }}</span>
            <span class="block text-sm text-surface-500 sm:hidden">{{ fila.elemento }}</span>
          </template>
          <template #col-volumen_presup_m3="{ fila }">
            <span>{{ decimales(fila.volumen_presup_m3) }}</span>
            <span class="block text-sm whitespace-nowrap text-surface-500 sm:hidden"
              >Vaciado {{ decimales(fila.volumen_vaciado_m3) }}</span
            >
          </template>
          <template #col-volumen_vaciado_m3="{ fila }">{{
            decimales(fila.volumen_vaciado_m3)
          }}</template>
          <template v-if="puedeGestionar" #acciones="{ fila }">
            <Button
              icon="pi pi-pencil"
              text
              rounded
              :aria-label="`Editar sector ${fila.nombre}`"
              :title="`Editar sector ${fila.nombre}`"
              @click="abrirSector(fila)"
            />
          </template>
        </TablaCrud>
      </div>
    </div>

    <template #dialogos>
      <DialogoFormulario
        v-model:visible="dialogoNivel"
        :titulo="editandoNivel ? `Editar nivel · ${editandoNivel.nombre}` : 'Nuevo nivel'"
        :guardando="formNivel.isSubmitting.value"
        @guardar="guardarNivel"
      >
        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Nombre del nivel"
          para="nombre_nivel"
          :error="formNivel.errors.value.nombre"
          obligatorio
          ancho="medio"
        >
          <InputText
            id="nombre_nivel"
            v-model="nombreNivel"
            v-bind="nombreNivelAttrs"
            maxlength="40"
            placeholder="Ej.: Piso 3"
            :invalid="!!formNivel.errors.value.nombre"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Orden"
          para="orden"
          :error="formNivel.errors.value.orden"
          obligatorio
          ancho="medio"
        >
          <!-- InputNumber no pasa los atributos al elemento que recibe el foco. -->
          <InputNumber
            v-model="orden"
            v-bind="{ ...ordenAttrs, ...entero }"
            input-id="orden"
            placeholder="Ej.: 4"
            :invalid="!!formNivel.errors.value.orden"
            :pt="{ pcInputText: { root: { 'aria-describedby': idError } } }"
          />
        </CampoFormulario>
      </DialogoFormulario>

      <DialogoFormulario
        v-model:visible="dialogoSector"
        :titulo="
          editandoSector
            ? `Editar sector · ${editandoSector.nombre}`
            : `Nuevo sector – ${nivel?.nombre ?? ''}`
        "
        :guardando="formSector.isSubmitting.value"
        @guardar="guardarSector"
      >
        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Nombre del sector"
          para="nombre_sector"
          :error="formSector.errors.value.nombre"
          obligatorio
        >
          <InputText
            id="nombre_sector"
            v-model="nombreSector"
            v-bind="nombreSectorAttrs"
            maxlength="40"
            placeholder="Ej.: Sector D"
            :invalid="!!formSector.errors.value.nombre"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Elemento estructural"
          para="presupuesto_id"
          :error="formSector.errors.value.presupuesto_id"
          obligatorio
          ancho="medio"
        >
          <!-- Select e InputNumber no pasan los atributos al elemento que recibe el foco. -->
          <Select
            v-model="presupuestoId"
            v-bind="presupuestoIdAttrs"
            label-id="presupuesto_id"
            :options="opcionesPartida"
            option-label="etiqueta"
            option-value="id"
            placeholder="Seleccione"
            :invalid="!!formSector.errors.value.presupuesto_id"
            :pt="{ label: { 'aria-describedby': idError } }"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Volumen presupuestado (m³)"
          para="volumen_presup_m3"
          :error="formSector.errors.value.volumen_presup_m3"
          obligatorio
          ancho="medio"
        >
          <InputNumber
            v-model="volumen"
            v-bind="{ ...volumenAttrs, ...numero }"
            input-id="volumen_presup_m3"
            placeholder="Ej.: 60.50"
            :invalid="!!formSector.errors.value.volumen_presup_m3"
            :pt="{ pcInputText: { root: { 'aria-describedby': idError } } }"
          />
        </CampoFormulario>

        <p data-disponible class="text-sm text-surface-500 sm:col-span-2">{{ notaDisponible }}</p>
      </DialogoFormulario>
    </template>
  </PaginaListado>
</template>
