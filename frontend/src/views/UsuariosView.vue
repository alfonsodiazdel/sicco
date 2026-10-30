<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import Message from 'primevue/message'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import DialogoFormulario from '@/components/organismos/DialogoFormulario.vue'
import BuscadorTabla from '@/components/moleculas/BuscadorTabla.vue'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'
import EstadoTag from '@/components/moleculas/EstadoTag.vue'
import { usuariosServicio } from '@/services/usuarios'
import { esquemaUsuario } from '@/validaciones/usuario'
import { etiquetasRol, type ProyectoOpcion, type UsuarioFila } from '@/types/usuarios'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'

const toast = useToast()

const usuarios = ref<UsuarioFila[]>([])
const proyectos = ref<ProyectoOpcion[]>([])
const cargando = ref(true)
const busqueda = ref('')

const columnas = [
  { campo: 'nombre', encabezado: 'Nombre' },
  { campo: 'email', encabezado: 'Correo' },
  { campo: 'rol', encabezado: 'Rol' },
  { campo: 'activo', encabezado: 'Estado' },
]
const opcionesRol = Object.entries(etiquetasRol).map(([valor, etiqueta]) => ({ valor, etiqueta }))

const nombreCompleto = (u: UsuarioFila) => `${u.nombres} ${u.apellidos}`

const filtrados = computed(() => {
  const texto = busqueda.value.trim().toLowerCase()
  return usuarios.value.filter((u) =>
    [nombreCompleto(u), u.email].some((v) => v.toLowerCase().includes(texto)),
  )
})

async function cargar() {
  usuarios.value = await usuariosServicio.listar()
}

onMounted(async () => {
  try {
    ;[proyectos.value] = await Promise.all([usuariosServicio.proyectos(), cargar()])
  } catch (error) {
    toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  } finally {
    cargando.value = false
  }
})

const dialogo = ref(false)
const editando = ref<UsuarioFila | null>(null)

const { defineField, handleSubmit, errors, setErrors, resetForm, isSubmitting } = useForm({
  validationSchema: toTypedSchema(esquemaUsuario),
})

const [nombres, nombresAttrs] = defineField('nombres')
const [apellidos, apellidosAttrs] = defineField('apellidos')
const [email, emailAttrs] = defineField('email')
const [rol, rolAttrs] = defineField('rol')
const [proyectoIds, proyectoIdsAttrs] = defineField('proyecto_ids')

function abrir(usuario: UsuarioFila | null = null) {
  editando.value = usuario
  resetForm({
    values: usuario
      ? {
          nombres: usuario.nombres,
          apellidos: usuario.apellidos,
          email: usuario.email,
          rol: usuario.rol,
          proyecto_ids: [...usuario.proyecto_ids],
        }
      : { proyecto_ids: [] },
  })
  dialogo.value = true
}

const guardar = handleSubmit(async (valores) => {
  try {
    if (editando.value) await usuariosServicio.actualizar(editando.value.id, valores)
    else await usuariosServicio.crear(valores)
    toast.add({
      severity: 'success',
      summary: editando.value ? 'Cambios guardados.' : 'Usuario registrado correctamente',
      life: 4000,
    })
    dialogo.value = false
    await cargar()
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) setErrors(campos)
    else toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  }
})

async function alternarEstado(usuario: UsuarioFila) {
  try {
    await usuariosServicio.cambiarEstado(usuario.id, !usuario.activo)
    toast.add({
      severity: 'success',
      summary: `${nombreCompleto(usuario)} quedó ${usuario.activo ? 'inactivo' : 'activo'}.`,
      life: 4000,
    })
    await cargar()
  } catch (error) {
    toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  }
}
</script>

<template>
  <PaginaListado titulo="Usuarios" subtitulo="Cuentas de acceso y roles del sistema">
    <template #acciones>
      <Button label="Nuevo usuario" icon="pi pi-plus" aria-label="Nuevo usuario" @click="abrir()" />
    </template>

    <template #herramientas>
      <div class="w-full sm:max-w-sm">
        <BuscadorTabla
          v-model="busqueda"
          placeholder="Buscar por nombre o correo"
          etiqueta="Buscar por nombre o correo"
        />
      </div>
    </template>

    <TablaCrud
      :filas="filtrados"
      :columnas="columnas"
      clave="id"
      vacio="No hay usuarios que coincidan con la búsqueda."
      :cargando="cargando"
    >
      <template #col-nombre="{ fila }">{{ nombreCompleto(fila) }}</template>
      <template #col-rol="{ fila }">{{ etiquetasRol[fila.rol] }}</template>
      <template #col-activo="{ fila }"><EstadoTag :activo="fila.activo" /></template>
      <template #acciones="{ fila }">
        <Button
          icon="pi pi-pencil"
          text
          rounded
          :aria-label="`Editar ${nombreCompleto(fila)}`"
          :title="`Editar ${nombreCompleto(fila)}`"
          @click="abrir(fila)"
        />
        <Button
          :icon="fila.activo ? 'pi pi-ban' : 'pi pi-check-circle'"
          text
          rounded
          :aria-label="`${fila.activo ? 'Desactivar' : 'Activar'} ${nombreCompleto(fila)}`"
          :title="`${fila.activo ? 'Desactivar' : 'Activar'} ${nombreCompleto(fila)}`"
          @click="alternarEstado(fila)"
        />
      </template>
    </TablaCrud>

    <template #dialogos>
      <DialogoFormulario
        v-model:visible="dialogo"
        :titulo="editando ? `Editar usuario · ${nombreCompleto(editando)}` : 'Nuevo usuario'"
        :guardando="isSubmitting"
        @guardar="guardar"
      >
        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Nombres"
          para="nombres"
          :error="errors.nombres"
          obligatorio
          ancho="medio"
        >
          <InputText
            id="nombres"
            v-model="nombres"
            v-bind="nombresAttrs"
            maxlength="100"
            placeholder="Ej.: Carlos Alberto"
            :invalid="!!errors.nombres"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Apellidos"
          para="apellidos"
          :error="errors.apellidos"
          obligatorio
          ancho="medio"
        >
          <InputText
            id="apellidos"
            v-model="apellidos"
            v-bind="apellidosAttrs"
            maxlength="100"
            placeholder="Ej.: Quispe Mamani"
            :invalid="!!errors.apellidos"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Correo electrónico"
          para="email"
          :error="errors.email"
          obligatorio
        >
          <InputText
            id="email"
            v-model="email"
            v-bind="emailAttrs"
            type="email"
            maxlength="255"
            autocomplete="off"
            placeholder="nombre@empresa.com"
            :invalid="!!errors.email"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Rol"
          para="rol"
          :error="errors.rol"
          obligatorio
        >
          <!-- Select y MultiSelect no pasan los atributos al elemento que recibe el foco. -->
          <Select
            v-model="rol"
            v-bind="rolAttrs"
            label-id="rol"
            :options="opcionesRol"
            option-label="etiqueta"
            option-value="valor"
            placeholder="Seleccione"
            :invalid="!!errors.rol"
            :pt="{ label: { 'aria-describedby': idError } }"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Proyectos asignados"
          para="proyecto_ids"
          :error="errors.proyecto_ids"
          obligatorio
        >
          <MultiSelect
            v-model="proyectoIds"
            v-bind="proyectoIdsAttrs"
            input-id="proyecto_ids"
            :options="proyectos"
            option-label="nombre"
            option-value="id"
            display="chip"
            placeholder="Seleccione"
            :invalid="!!errors.proyecto_ids"
            :pt="{ hiddenInput: { 'aria-describedby': idError } }"
            fluid
          />
        </CampoFormulario>

        <Message v-if="!editando" severity="info" size="small" class="sm:col-span-2">
          Al guardar, se envía una contraseña temporal al correo; el usuario la cambia en su primer
          ingreso.
        </Message>
      </DialogoFormulario>
    </template>
  </PaginaListado>
</template>
