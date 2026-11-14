<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import DialogoFormulario from '@/components/organismos/DialogoFormulario.vue'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'
import EstadoTag from '@/components/moleculas/EstadoTag.vue'
import { elementosServicio } from '@/services/elementos'
import { esquemaElemento } from '@/validaciones/elemento'
import type { ElementoFila } from '@/types/elementos'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const toast = useToast()
const confirm = useConfirm()
const auth = useAuthStore()

// El administrador entra en modo consulta: el catálogo lo mantiene Oficina técnica.
const puedeGestionar = computed(() => auth.tieneRol('oficina_tecnica'))

const elementos = ref<ElementoFila[]>([])
const cargando = ref(true)

const columnas = [
  { campo: 'nombre', encabezado: 'Elemento' },
  { campo: 'activo', encabezado: 'Estado' },
]

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

async function cargar() {
  elementos.value = await elementosServicio.todos()
}

onMounted(async () => {
  try {
    await cargar()
  } catch (error) {
    avisarError(error)
  } finally {
    cargando.value = false
  }
})

const dialogo = ref(false)
const editando = ref<ElementoFila | null>(null)

const { defineField, handleSubmit, errors, setErrors, resetForm, isSubmitting } = useForm({
  validationSchema: toTypedSchema(esquemaElemento),
})
const [nombre, nombreAttrs] = defineField('nombre')

function abrir(elemento: ElementoFila | null = null) {
  editando.value = elemento
  resetForm({ values: { nombre: elemento?.nombre ?? '' } })
  dialogo.value = true
}

const guardar = handleSubmit(async (valores) => {
  try {
    if (editando.value) await elementosServicio.actualizar(editando.value.id, valores)
    else await elementosServicio.crear(valores)
    toast.add({
      severity: 'success',
      summary: editando.value ? 'Cambios guardados.' : 'Elemento registrado',
      life: 4000,
    })
    dialogo.value = false
    await cargar()
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) setErrors(campos)
    else avisarError(error)
  }
})

async function cambiarEstado(elemento: ElementoFila) {
  try {
    await elementosServicio.actualizar(elemento.id, {
      nombre: elemento.nombre,
      activo: !elemento.activo,
    })
    await cargar()
  } catch (error) {
    avisarError(error)
  }
}

function confirmarEliminacion(elemento: ElementoFila) {
  confirm.require({
    header: 'Eliminar elemento',
    message: `¿Eliminar el elemento ${elemento.nombre}?`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancelar', severity: 'secondary', text: true },
    acceptProps: { label: 'Eliminar', severity: 'danger' },
    accept: async () => {
      try {
        await elementosServicio.eliminar(elemento.id)
        toast.add({ severity: 'success', summary: 'Elemento eliminado', life: 3000 })
        await cargar()
      } catch (error) {
        avisarError(error)
      }
    },
  })
}
</script>

<template>
  <PaginaListado
    titulo="Elementos estructurales"
    subtitulo="Los que se ofrecen al registrar una partida; uno en uso solo se puede desactivar"
  >
    <template v-if="puedeGestionar" #acciones>
      <Button
        label="Nuevo elemento"
        icon="pi pi-plus"
        aria-label="Nuevo elemento"
        @click="abrir()"
      />
    </template>

    <TablaCrud
      :filas="elementos"
      :columnas="columnas"
      clave="id"
      vacio="Aún no hay elementos registrados."
      :cargando="cargando"
    >
      <template #col-activo="{ fila }"><EstadoTag :activo="fila.activo" /></template>
      <template v-if="puedeGestionar" #acciones="{ fila }">
        <Button
          icon="pi pi-pencil"
          text
          rounded
          :aria-label="`Editar ${fila.nombre}`"
          :title="`Editar ${fila.nombre}`"
          @click="abrir(fila)"
        />
        <Button
          :icon="fila.activo ? 'pi pi-eye-slash' : 'pi pi-eye'"
          text
          rounded
          severity="secondary"
          :aria-label="`${fila.activo ? 'Desactivar' : 'Activar'} ${fila.nombre}`"
          :title="`${fila.activo ? 'Desactivar' : 'Activar'} ${fila.nombre}`"
          @click="cambiarEstado(fila)"
        />
        <Button
          icon="pi pi-trash"
          text
          rounded
          severity="danger"
          :aria-label="`Eliminar ${fila.nombre}`"
          :title="`Eliminar ${fila.nombre}`"
          @click="confirmarEliminacion(fila)"
        />
      </template>
    </TablaCrud>

    <template #dialogos>
      <DialogoFormulario
        v-model:visible="dialogo"
        :titulo="editando ? `Editar elemento · ${editando.nombre}` : 'Nuevo elemento'"
        :guardando="isSubmitting"
        @guardar="guardar"
      >
        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Nombre"
          para="nombre"
          :error="errors.nombre"
          obligatorio
        >
          <InputText
            id="nombre"
            v-model="nombre"
            v-bind="nombreAttrs"
            maxlength="30"
            placeholder="Ej.: Muro de contención"
            :invalid="!!errors.nombre"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>
      </DialogoFormulario>
    </template>
  </PaginaListado>
</template>
