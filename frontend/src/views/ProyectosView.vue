<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import DialogoFormulario from '@/components/organismos/DialogoFormulario.vue'
import BuscadorTabla from '@/components/moleculas/BuscadorTabla.vue'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'
import EstadoTag from '@/components/moleculas/EstadoTag.vue'
import { proyectosServicio } from '@/services/proyectos'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import { menu } from '@/router/menu'
import { datosDeProyecto, esquemaProyecto, valoresDeProyecto } from '@/validaciones/proyecto'
import { estadosProyecto, type ProyectoFila } from '@/types/proyectos'
import type { IngenieroOpcion } from '@/types/usuarios'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'

const toast = useToast()
const router = useRouter()
const auth = useAuthStore()
const proyectoActual = useProyectoActualStore()

const rutaPresupuesto = '/partidas'
const rolesPresupuesto = menu.find((item) => item.ruta === rutaPresupuesto)?.roles
// Como el menú: el administrador también entra a consultar el presupuesto.
const verPresupuesto = computed(() => !rolesPresupuesto || auth.puedeVer(...rolesPresupuesto))
// La lista trae todos los proyectos, pero el presupuesto solo se abre en los activos asignados:
// con otro, la vista mostraba en silencio el presupuesto de un proyecto distinto.
const conPresupuesto = (proyecto: ProyectoFila) =>
  verPresupuesto.value && proyectoActual.proyectos.some((p) => p.id === proyecto.id)
// El administrador entra en modo consulta: crear y editar quedan para Oficina técnica.
const puedeGestionar = computed(() => auth.tieneRol('oficina_tecnica'))
// En celular las acciones van apiladas y angostas: lado a lado, la tabla se desplaza a lo ancho
// y la columna fija de acciones tapa el estado.
// El tema de PrimeVue va en una capa posterior a las utilidades: por eso el `!`.
const accionCompacta = 'max-sm:w-8!'
const presupuestoSoloIconoEnCelular = {
  root: { class: `${accionCompacta} max-sm:px-0! max-sm:rounded-full!` },
  icon: { class: 'sm:hidden' },
  label: { class: 'hidden sm:inline' },
}

function abrirPresupuesto(proyecto: ProyectoFila) {
  proyectoActual.elegir(proyecto.id)
  router.push(rutaPresupuesto)
}

const proyectos = ref<ProyectoFila[]>([])
const ingenieros = ref<IngenieroOpcion[]>([])
const cargando = ref(true)
const busqueda = ref('')

const columnas = [
  { campo: 'codigo', encabezado: 'Código' },
  { campo: 'nombre', encabezado: 'Proyecto' },
  { campo: 'direccion', encabezado: 'Dirección', secundaria: true },
  { campo: 'fecha_inicio', encabezado: 'Inicio', secundaria: true },
  { campo: 'fecha_fin', encabezado: 'Fin estimado', secundaria: true },
  { campo: 'estado', encabezado: 'Estado' },
]

const formatoFecha = (iso: string) => iso.split('-').reverse().join('/')

const filtrados = computed(() => {
  const texto = busqueda.value.trim().toLowerCase()
  return proyectos.value.filter((p) =>
    [p.codigo, p.nombre, p.direccion].some((v) => v.toLowerCase().includes(texto)),
  )
})

async function cargar() {
  proyectos.value = await proyectosServicio.listar()
}

async function cargarIngenieros() {
  // Solo quien gestiona abre el formulario; la API no le da los responsables a nadie más.
  if (puedeGestionar.value) ingenieros.value = await proyectosServicio.ingenieros()
}

onMounted(async () => {
  try {
    await Promise.all([
      cargar(),
      cargarIngenieros(),
      verPresupuesto.value && proyectoActual.cargar(),
    ])
  } catch (error) {
    toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  } finally {
    cargando.value = false
  }
})

const dialogo = ref(false)
const editando = ref<ProyectoFila | null>(null)

const { defineField, handleSubmit, errors, setErrors, resetForm, isSubmitting } = useForm({
  validationSchema: toTypedSchema(esquemaProyecto),
})

const [codigo, codigoAttrs] = defineField('codigo')
const [nombre, nombreAttrs] = defineField('nombre')
const [direccion, direccionAttrs] = defineField('direccion')
const [fechaInicio, fechaInicioAttrs] = defineField('fecha_inicio')
const [fechaFin, fechaFinAttrs] = defineField('fecha_fin')
const [ingenieroId, ingenieroIdAttrs] = defineField('ingeniero_id')
const [estado, estadoAttrs] = defineField('estado')

// Un proyecto conserva a su ingeniero aunque haya quedado inactivo; no se ofrece para otros.
const ingenieroInactivo = ref<IngenieroOpcion | null>(null)
const opcionesIngeniero = computed(() =>
  ingenieroInactivo.value ? [...ingenieros.value, ingenieroInactivo.value] : ingenieros.value,
)

async function abrir(proyecto: ProyectoFila | null = null) {
  ingenieroInactivo.value = null
  const id = proyecto?.ingeniero_id
  if (id !== undefined && !ingenieros.value.some((i) => i.id === id)) {
    try {
      const actual = (await proyectosServicio.ingenieros(id)).find((i) => i.id === id)
      if (actual) ingenieroInactivo.value = { id, nombre: `${actual.nombre} (inactivo)` }
    } catch (error) {
      toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
    }
  }
  editando.value = proyecto
  resetForm({ values: proyecto ? valoresDeProyecto(proyecto) : {} })
  dialogo.value = true
}

const guardar = handleSubmit(async (valores) => {
  try {
    const datos = datosDeProyecto(valores)
    if (editando.value) await proyectosServicio.actualizar(editando.value.id, datos)
    else await proyectosServicio.crear(datos)
    toast.add({
      severity: 'success',
      summary: editando.value ? 'Cambios guardados.' : 'Proyecto registrado correctamente',
      life: 4000,
    })
    dialogo.value = false
    // El selector del encabezado ofrece solo los activos: un cambio de estado o nombre lo afecta.
    await Promise.all([cargar(), verPresupuesto.value && proyectoActual.recargar()])
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) setErrors(campos)
    else toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  }
})
</script>

<template>
  <PaginaListado titulo="Proyectos" subtitulo="Obras registradas y su presupuesto de concreto">
    <template v-if="puedeGestionar" #acciones>
      <Button
        label="Nuevo proyecto"
        icon="pi pi-plus"
        aria-label="Nuevo proyecto"
        @click="abrir()"
      />
    </template>

    <template #herramientas>
      <div class="w-full sm:max-w-sm">
        <BuscadorTabla
          v-model="busqueda"
          placeholder="Buscar proyecto"
          etiqueta="Buscar proyecto"
        />
      </div>
    </template>

    <TablaCrud
      :filas="filtrados"
      :columnas="columnas"
      clave="id"
      vacio="No hay proyectos que coincidan con la búsqueda."
      :cargando="cargando"
    >
      <template #col-fecha_inicio="{ fila }">{{ formatoFecha(fila.fecha_inicio) }}</template>
      <template #col-fecha_fin="{ fila }">{{ formatoFecha(fila.fecha_fin) }}</template>
      <template #col-estado="{ fila }">
        <EstadoTag :activo="fila.estado === 'Activo'" inactivo="Finalizado" />
      </template>
      <template v-if="puedeGestionar || verPresupuesto" #acciones="{ fila }">
        <div class="flex flex-col items-center sm:flex-row sm:gap-1">
          <Button
            v-if="conPresupuesto(fila)"
            label="Presupuesto"
            icon="pi pi-calculator"
            text
            :pt="presupuestoSoloIconoEnCelular"
            :aria-label="`Presupuesto de ${fila.nombre}`"
            :title="`Presupuesto de ${fila.nombre}`"
            @click="abrirPresupuesto(fila)"
          />
          <Button
            v-if="puedeGestionar"
            icon="pi pi-pencil"
            text
            rounded
            :class="accionCompacta"
            :aria-label="`Editar ${fila.nombre}`"
            :title="`Editar ${fila.nombre}`"
            @click="abrir(fila)"
          />
        </div>
      </template>
    </TablaCrud>

    <template #dialogos>
      <DialogoFormulario
        v-model:visible="dialogo"
        :titulo="editando ? `Editar proyecto · ${editando.nombre}` : 'Nuevo proyecto'"
        :guardando="isSubmitting"
        @guardar="guardar"
      >
        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Código"
          para="codigo"
          :error="errors.codigo"
          obligatorio
          ancho="medio"
        >
          <InputText
            id="codigo"
            v-model="codigo"
            v-bind="codigoAttrs"
            maxlength="20"
            placeholder="Ej.: PRY-004"
            :invalid="!!errors.codigo"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Nombre del proyecto"
          para="nombre"
          :error="errors.nombre"
          obligatorio
        >
          <InputText
            id="nombre"
            v-model="nombre"
            v-bind="nombreAttrs"
            maxlength="120"
            placeholder="Ej.: Residencial Los Álamos"
            :invalid="!!errors.nombre"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Dirección"
          para="direccion"
          :error="errors.direccion"
          obligatorio
        >
          <InputText
            id="direccion"
            v-model="direccion"
            v-bind="direccionAttrs"
            maxlength="150"
            placeholder="Ej.: Av. Primavera 1250, Santiago de Surco"
            :invalid="!!errors.direccion"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Fecha de inicio"
          para="fecha_inicio"
          :error="errors.fecha_inicio"
          obligatorio
          ancho="medio"
        >
          <!-- DatePicker y Select no pasan los atributos al elemento que recibe el foco. -->
          <DatePicker
            v-model="fechaInicio"
            v-bind="fechaInicioAttrs"
            input-id="fecha_inicio"
            date-format="dd/mm/yy"
            placeholder="dd/mm/aaaa"
            :invalid="!!errors.fecha_inicio"
            :pt="{ pcInputText: { root: { 'aria-describedby': idError } } }"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Fecha de fin estimada"
          para="fecha_fin"
          :error="errors.fecha_fin"
          obligatorio
          ancho="medio"
        >
          <DatePicker
            v-model="fechaFin"
            v-bind="fechaFinAttrs"
            input-id="fecha_fin"
            date-format="dd/mm/yy"
            placeholder="dd/mm/aaaa"
            :invalid="!!errors.fecha_fin"
            :pt="{ pcInputText: { root: { 'aria-describedby': idError } } }"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Ingeniero responsable"
          para="ingeniero_id"
          :error="errors.ingeniero_id"
          obligatorio
        >
          <Select
            v-model="ingenieroId"
            v-bind="ingenieroIdAttrs"
            label-id="ingeniero_id"
            :options="opcionesIngeniero"
            option-label="nombre"
            option-value="id"
            placeholder="Seleccione"
            :invalid="!!errors.ingeniero_id"
            :pt="{ label: { 'aria-describedby': idError } }"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-if="editando"
          v-slot="{ idError }"
          etiqueta="Estado"
          para="estado"
          :error="errors.estado"
          obligatorio
          ancho="medio"
        >
          <Select
            v-model="estado"
            v-bind="estadoAttrs"
            label-id="estado"
            :options="[...estadosProyecto]"
            placeholder="Seleccione"
            :invalid="!!errors.estado"
            :pt="{ label: { 'aria-describedby': idError } }"
            fluid
          />
        </CampoFormulario>
      </DialogoFormulario>
    </template>
  </PaginaListado>
</template>
