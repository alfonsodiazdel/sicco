<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Tag from 'primevue/tag'
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
import { aIso } from '@/validaciones/proyecto'
import { esquemaSector, valoresDeSector } from '@/validaciones/sector'
import type { NivelFila, SectorFila } from '@/types/niveles'
import type { PartidaFila } from '@/types/partidas'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'

const toast = useToast()
const confirm = useConfirm()
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
// La descripción distingue partidas con el mismo elemento y resistencia. Los elementos sin
// volumen disponible se marcan, pero se pueden elegir para ver el aviso.
const opcionesPartida = computed(() =>
  partidas.value.map((p) => ({
    id: p.id,
    etiqueta: etiquetaPartida(p),
    descripcion: p.descripcion,
    texto: `${etiquetaPartida(p)} · ${p.descripcion}`,
    sinDisponible: disponible(p.id) <= 0,
  })),
)

const columnas = [
  { campo: 'nombre', encabezado: 'Sector' },
  { campo: 'elemento', encabezado: 'Elemento', secundaria: true },
  { campo: 'volumen_presup_m3', encabezado: 'Volumen presupuestado (m³)', numerica: true },
  { campo: 'volumen_vaciado_m3', encabezado: 'Vaciado (m³)', secundaria: true, numerica: true },
  { campo: 'fecha_culminacion', encabezado: 'Estado', secundaria: true },
]

/** `YYYY-MM-DD` a `dd/mm/aaaa`, sin pasar por Date: la zona horaria no mueve el día. */
const fechaCorta = (iso: string) => iso.split('-').reverse().join('/')

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
  formNivel.resetForm({ values: n ? valoresDeNivel(n) : {} }, { force: true })
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

// Solo el disponible del elemento elegido; sin elemento no se muestra nada.
const partidaElegida = computed(() => partidas.value.find((p) => p.id === presupuestoId.value))
const elementoCopado = computed(
  () => !!partidaElegida.value && disponible(partidaElegida.value.id) <= 0,
)
const notaDisponible = computed(() => {
  const partida = partidaElegida.value
  if (!partida) return ''
  if (elementoCopado.value)
    return (
      `${etiquetaPartida(partida)} ya no tiene volumen disponible: sus ` +
      `${decimales(partida.metrado_m3)} m³ están asignados a otros sectores. ` +
      'Reduzca el volumen de alguno de ellos o elija otro elemento.'
    )
  return `Disponible para ${etiquetaPartida(partida)}: ${decimales(disponible(partida.id))} m³.`
})
// Lo que queda del elemento con el volumen escrito; en centésimos, como volumenDisponible.
const restante = computed(() => {
  const escrito = volumenEscrito.value
  if (!partidaElegida.value || elementoCopado.value || !escrito) return null
  return (Math.round(disponible(partidaElegida.value.id) * 100) - Math.round(escrito * 100)) / 100
})
const notaRestante = computed(() => {
  if (restante.value === null) return ''
  return restante.value < 0
    ? `Excede en ${decimales(-restante.value)} m³.`
    : `Quedarán ${decimales(restante.value)} m³ después de este sector.`
})

const formSector = useForm({ validationSchema: toTypedSchema(esquemaSector(disponible)) })
const [nombreSector, nombreSectorAttrs] = formSector.defineField('nombre')
const [presupuestoId, presupuestoIdAttrs] = formSector.defineField('presupuesto_id')
const [volumen, volumenAttrs] = formSector.defineField('volumen_presup_m3')

// InputNumber actualiza el v-model al salir del campo; su evento input da el valor tecla a tecla.
const volumenEscrito = ref<number | null>(null)
watch(volumen, (valor) => (volumenEscrito.value = valor ?? null))
// Un pt nuevo en cada render repinta InputNumber con su v-model (aún vacío) y borra lo escrito
// mientras la nota se actualiza tecla a tecla; por eso es un computed y no un objeto en la plantilla.
// El booleano evita recalcularlo cuando cambia el error de otro campo.
const volumenConError = computed(() => !!formSector.errors.value.volumen_presup_m3)
const ptVolumen = computed(() => ({
  pcInputText: {
    root: { 'aria-describedby': volumenConError.value ? 'volumen_presup_m3-error' : undefined },
  },
}))

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
  // force: sin él, vee-validate mezcla {} con los valores del último sector editado.
  formSector.resetForm({ values: s ? valoresDeSector(s) : {} }, { force: true })
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
    // «sector» no es un campo del formulario: el sector se culminó mientras se editaba.
    if (campos.sector) avisarError(error)
    else if (Object.keys(campos).length) formSector.setErrors(campos)
    else avisarError(error)
  }
})

// CU05: culminar un sector. Su vaciado pasa a costo real en el reporte EAT (CU15) y ya no recibe guías.
const dialogoCulminar = ref(false)
const culminando = ref<SectorFila | null>(null)
const fechaCulminacion = ref<Date | null>(null)
const errorCulminacion = ref<string | null>(null)
const guardandoCulminacion = ref(false)
const hoy = (() => {
  const ahora = new Date()
  return new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate())
})()

function abrirCulminar(s: SectorFila) {
  culminando.value = s
  fechaCulminacion.value = hoy
  errorCulminacion.value = null
  dialogoCulminar.value = true
}

async function guardarCulminacion() {
  const s = culminando.value
  if (!s) return
  if (!fechaCulminacion.value) {
    errorCulminacion.value = 'Ingrese la fecha de culminación'
    return
  }
  guardandoCulminacion.value = true
  try {
    await nivelesServicio.culminarSector(s.id, aIso(fechaCulminacion.value))
    toast.add({ severity: 'success', summary: 'Sector culminado correctamente', life: 4000 })
    dialogoCulminar.value = false
    await cargar()
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (campos.fecha_culminacion) errorCulminacion.value = campos.fecha_culminacion
    else avisarError(error)
  } finally {
    guardandoCulminacion.value = false
  }
}

function reabrir(s: SectorFila) {
  confirm.require({
    header: 'Reabrir sector',
    message:
      `¿Reabrir el sector ${s.nombre}? Volverá a recibir guías y, en el reporte EAT, ` +
      'su costo vuelve a proyectarse.',
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancelar', severity: 'secondary', text: true },
    acceptProps: { label: 'Reabrir', severity: 'warn' },
    accept: async () => {
      try {
        await nivelesServicio.reabrirSector(s.id)
        toast.add({ severity: 'success', summary: 'Sector reabierto correctamente', life: 4000 })
        await cargar()
      } catch (error) {
        avisarError(error)
      }
    },
  })
}

// CU05: corregir errores de planificación. El backend repite las reglas; aquí solo se ofrece el botón
// cuando aplica: nivel sin sectores, sector abierto y sin concreto vaciado (sin guías asignadas).
const sinSectores = (n: NivelFila) => !sectores.value.some((s) => s.nivel_id === n.id)
const eliminable = (s: SectorFila) => !s.fecha_culminacion && s.volumen_vaciado_m3 === 0

function eliminarNivel(n: NivelFila) {
  confirm.require({
    header: 'Eliminar nivel',
    message: `¿Eliminar el nivel ${n.nombre}?`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancelar', severity: 'secondary', text: true },
    acceptProps: { label: 'Eliminar', severity: 'danger' },
    accept: async () => {
      try {
        await nivelesServicio.eliminarNivel(n.id)
        toast.add({ severity: 'success', summary: 'Nivel eliminado correctamente', life: 4000 })
        // Si era el elegido, se elige el primero que quede.
        if (nivelId.value === n.id) nivelId.value = null
        await cargar()
      } catch (error) {
        avisarError(error)
      }
    },
  })
}

function eliminarSector(s: SectorFila) {
  confirm.require({
    header: 'Eliminar sector',
    message:
      `¿Eliminar el sector ${s.nombre}? Su volumen presupuestado vuelve a quedar disponible ` +
      'en la partida.',
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancelar', severity: 'secondary', text: true },
    acceptProps: { label: 'Eliminar', severity: 'danger' },
    accept: async () => {
      try {
        await nivelesServicio.eliminarSector(s.id)
        toast.add({ severity: 'success', summary: 'Sector eliminado correctamente', life: 4000 })
        await cargar()
      } catch (error) {
        avisarError(error)
      }
    },
  })
}

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
            <Button
              v-if="puedeGestionar && sinSectores(n)"
              icon="pi pi-trash"
              text
              rounded
              severity="danger"
              :aria-label="`Eliminar nivel ${n.nombre}`"
              :title="`Eliminar nivel ${n.nombre}`"
              @click="eliminarNivel(n)"
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
            <span v-if="fila.fecha_culminacion" class="block text-sm text-surface-500 sm:hidden"
              >Culminado {{ fechaCorta(fila.fecha_culminacion) }}</span
            >
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
          <template #col-fecha_culminacion="{ fila }">
            <Tag
              v-if="fila.fecha_culminacion"
              :value="`Culminado ${fechaCorta(fila.fecha_culminacion)}`"
              severity="secondary"
              icon="pi pi-lock"
              data-culminado
            />
            <Tag v-else value="Abierto" severity="info" />
          </template>
          <template v-if="puedeGestionar" #acciones="{ fila }">
            <template v-if="fila.fecha_culminacion">
              <Button
                icon="pi pi-lock-open"
                text
                rounded
                :aria-label="`Reabrir sector ${fila.nombre}`"
                :title="`Reabrir sector ${fila.nombre}`"
                @click="reabrir(fila)"
              />
            </template>
            <template v-else>
              <Button
                icon="pi pi-pencil"
                text
                rounded
                :aria-label="`Editar sector ${fila.nombre}`"
                :title="`Editar sector ${fila.nombre}`"
                @click="abrirSector(fila)"
              />
              <Button
                icon="pi pi-check-circle"
                text
                rounded
                :aria-label="`Culminar sector ${fila.nombre}`"
                :title="`Culminar sector ${fila.nombre}`"
                @click="abrirCulminar(fila)"
              />
              <Button
                v-if="eliminable(fila)"
                icon="pi pi-trash"
                text
                rounded
                severity="danger"
                :aria-label="`Eliminar sector ${fila.nombre}`"
                :title="`Eliminar sector ${fila.nombre}`"
                @click="eliminarSector(fila)"
              />
            </template>
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
        ancho="40rem"
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
        >
          <!-- Select e InputNumber no pasan los atributos al elemento que recibe el foco. -->
          <Select
            v-model="presupuestoId"
            v-bind="presupuestoIdAttrs"
            label-id="presupuesto_id"
            :options="opcionesPartida"
            option-label="texto"
            option-value="id"
            placeholder="Seleccione"
            :invalid="!!formSector.errors.value.presupuesto_id"
            :pt="{
              label: { 'aria-describedby': idError },
              overlay: { style: { maxWidth: 'calc(100vw - 2rem)' } },
            }"
            fluid
          >
            <template #value="{ value, placeholder }">
              <template v-if="partidaElegida && value">
                {{ etiquetaPartida(partidaElegida) }}
                <span class="text-surface-500">· {{ partidaElegida.descripcion }}</span>
              </template>
              <template v-else>{{ placeholder }}</template>
            </template>
            <template #option="{ option }">
              <!-- Las descripciones largas se ajustan para que la lista no salga de la pantalla. -->
              <div
                data-opcion
                class="flex w-full items-start justify-between gap-3 whitespace-normal"
              >
                <div class="min-w-0">
                  <div>{{ option.etiqueta }}</div>
                  <div class="text-sm text-surface-500">{{ option.descripcion }}</div>
                </div>
                <span v-if="option.sinDisponible" class="shrink-0 text-sm text-amber-700">
                  Sin disponible
                </span>
              </div>
            </template>
          </Select>
        </CampoFormulario>

        <CampoFormulario
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
            :pt="ptVolumen"
            @input="(e) => (volumenEscrito = typeof e.value === 'number' ? e.value : null)"
          />
        </CampoFormulario>

        <Message
          v-if="elementoCopado"
          data-disponible
          severity="warn"
          icon="pi pi-exclamation-triangle"
          class="sm:col-span-2"
        >
          {{ notaDisponible }}
        </Message>
        <p
          v-else-if="notaDisponible"
          data-disponible
          class="text-sm text-surface-500 sm:col-span-2"
          aria-live="polite"
        >
          {{ notaDisponible }}
          <span
            v-if="notaRestante"
            data-restante
            :class="{ 'font-medium text-red-700': restante !== null && restante < 0 }"
          >
            {{ notaRestante }}
          </span>
        </p>
      </DialogoFormulario>

      <DialogoFormulario
        v-model:visible="dialogoCulminar"
        :titulo="`Culminar sector · ${culminando?.nombre ?? ''}`"
        :guardando="guardandoCulminacion"
        @guardar="guardarCulminacion"
      >
        <CampoFormulario
          etiqueta="Fecha de culminación"
          para="fecha_culminacion"
          :error="errorCulminacion ?? undefined"
          obligatorio
          ancho="medio"
        >
          <DatePicker
            v-model="fechaCulminacion"
            input-id="fecha_culminacion"
            date-format="dd/mm/yy"
            placeholder="dd/mm/aaaa"
            :max-date="hoy"
            :invalid="!!errorCulminacion"
            fluid
          />
        </CampoFormulario>
        <p class="text-sm text-surface-500 sm:col-span-2" data-nota-culminar>
          El sector ya no recibirá guías. En el reporte EAT, lo vaciado hasta esta fecha cuenta como
          costo real y deja de proyectarse. Puede reabrirlo si necesita corregirlo.
        </p>
      </DialogoFormulario>
    </template>
  </PaginaListado>
</template>
