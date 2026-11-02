<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import DialogoFormulario from '@/components/organismos/DialogoFormulario.vue'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'
import { gruposServicio } from '@/services/grupos'
import { esquemaGrupo, valoresDeGrupo } from '@/validaciones/grupo'
import type { GrupoFila } from '@/types/grupos'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const toast = useToast()
const confirm = useConfirm()
const auth = useAuthStore()

// El administrador entra en modo consulta: el catálogo lo mantiene Oficina técnica.
const puedeGestionar = computed(() => auth.tieneRol('oficina_tecnica'))

const grupos = ref<GrupoFila[]>([])
const cargando = ref(true)

const columnas = [
  { campo: 'orden', encabezado: 'Orden', numerica: true },
  { campo: 'nombre', encabezado: 'Grupo' },
]

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

async function cargar() {
  grupos.value = await gruposServicio.listar()
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
const editando = ref<GrupoFila | null>(null)

const { defineField, handleSubmit, errors, setErrors, resetForm, isSubmitting } = useForm({
  validationSchema: toTypedSchema(esquemaGrupo),
})

const [nombre, nombreAttrs] = defineField('nombre')
const [orden, ordenAttrs] = defineField('orden')

function abrir(grupo: GrupoFila | null = null) {
  editando.value = grupo
  // Uno nuevo va al final; se puede cambiar al registrarlo.
  const siguiente = Math.max(0, ...grupos.value.map((g) => g.orden)) + 1
  resetForm({ values: grupo ? valoresDeGrupo(grupo) : { orden: siguiente } })
  dialogo.value = true
}

const guardar = handleSubmit(async (valores) => {
  try {
    if (editando.value) await gruposServicio.actualizar(editando.value.id, valores)
    else await gruposServicio.crear(valores)
    toast.add({
      severity: 'success',
      summary: editando.value ? 'Cambios guardados.' : 'Grupo registrado',
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

function confirmarEliminacion(grupo: GrupoFila) {
  confirm.require({
    header: 'Eliminar grupo',
    message: `¿Eliminar el grupo ${grupo.nombre}?`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancelar', severity: 'secondary', text: true },
    acceptProps: { label: 'Eliminar', severity: 'danger' },
    accept: async () => {
      try {
        await gruposServicio.eliminar(grupo.id)
        toast.add({ severity: 'success', summary: 'Grupo eliminado', life: 3000 })
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
    titulo="Grupos de partidas"
    subtitulo="Agrupan las partidas del presupuesto y ordenan sus subtotales en los reportes"
  >
    <template v-if="puedeGestionar" #acciones>
      <Button label="Nuevo grupo" icon="pi pi-plus" aria-label="Nuevo grupo" @click="abrir()" />
    </template>

    <TablaCrud
      :filas="grupos"
      :columnas="columnas"
      clave="id"
      vacio="Aún no hay grupos registrados."
      :cargando="cargando"
    >
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
        :titulo="editando ? `Editar grupo · ${editando.nombre}` : 'Nuevo grupo'"
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
            maxlength="60"
            placeholder="Ej.: Cimentación"
            :invalid="!!errors.nombre"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Orden"
          para="orden"
          :error="errors.orden"
          obligatorio
          ancho="medio"
        >
          <!-- InputNumber no pasa los atributos al elemento que recibe el foco. -->
          <InputNumber
            v-model="orden"
            v-bind="ordenAttrs"
            input-id="orden"
            :min="1"
            :use-grouping="false"
            :invalid="!!errors.orden"
            :pt="{ pcInputText: { root: { 'aria-describedby': idError } } }"
            fluid
          />
        </CampoFormulario>
      </DialogoFormulario>
    </template>
  </PaginaListado>
</template>
