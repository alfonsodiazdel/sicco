<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import DialogoFormulario from '@/components/organismos/DialogoFormulario.vue'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'
import EstadoTag from '@/components/moleculas/EstadoTag.vue'
import { resistenciasServicio } from '@/services/resistencias'
import { esquemaResistencia, nombreDeResistencia } from '@/validaciones/resistencia'
import type { ResistenciaFila } from '@/types/resistencias'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const toast = useToast()
const confirm = useConfirm()
const auth = useAuthStore()

// El administrador entra en modo consulta: el catálogo lo mantiene Oficina técnica.
const puedeGestionar = computed(() => auth.tieneRol('oficina_tecnica'))

const resistencias = ref<ResistenciaFila[]>([])
const cargando = ref(true)

const columnas = [
  { campo: 'nombre', encabezado: "Resistencia f'c" },
  { campo: 'activo', encabezado: 'Estado' },
]

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

async function cargar() {
  resistencias.value = await resistenciasServicio.todas()
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
const editando = ref<ResistenciaFila | null>(null)

const { defineField, handleSubmit, errors, setErrors, resetForm, isSubmitting } = useForm({
  validationSchema: toTypedSchema(esquemaResistencia),
})
const [fc, fcAttrs] = defineField('fc_kg_cm2')

function abrir(resistencia: ResistenciaFila | null = null) {
  editando.value = resistencia
  resetForm({ values: { fc_kg_cm2: resistencia?.fc_kg_cm2 } })
  dialogo.value = true
}

const guardar = handleSubmit(async (valores) => {
  // El nombre sale del f'c, como lo muestran el presupuesto y las guías.
  const datos = { fc_kg_cm2: valores.fc_kg_cm2, nombre: nombreDeResistencia(valores.fc_kg_cm2) }
  try {
    if (editando.value) await resistenciasServicio.actualizar(editando.value.id, datos)
    else await resistenciasServicio.crear(datos)
    toast.add({
      severity: 'success',
      summary: editando.value ? 'Cambios guardados.' : 'Resistencia registrada',
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

async function cambiarEstado(resistencia: ResistenciaFila) {
  try {
    await resistenciasServicio.actualizar(resistencia.id, {
      fc_kg_cm2: resistencia.fc_kg_cm2,
      nombre: resistencia.nombre,
      activo: !resistencia.activo,
    })
    await cargar()
  } catch (error) {
    avisarError(error)
  }
}

function confirmarEliminacion(resistencia: ResistenciaFila) {
  confirm.require({
    header: 'Eliminar resistencia',
    message: `¿Eliminar la resistencia ${resistencia.nombre}?`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancelar', severity: 'secondary', text: true },
    acceptProps: { label: 'Eliminar', severity: 'danger' },
    accept: async () => {
      try {
        await resistenciasServicio.eliminar(resistencia.id)
        toast.add({ severity: 'success', summary: 'Resistencia eliminada', life: 3000 })
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
    titulo="Resistencias f'c"
    subtitulo="Las que se ofrecen en el presupuesto, las guías y las facturas; una en uso solo se puede desactivar"
  >
    <template v-if="puedeGestionar" #acciones>
      <Button
        label="Nueva resistencia"
        icon="pi pi-plus"
        aria-label="Nueva resistencia"
        @click="abrir()"
      />
    </template>

    <TablaCrud
      :filas="resistencias"
      :columnas="columnas"
      clave="id"
      vacio="Aún no hay resistencias registradas."
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
        :titulo="editando ? `Editar resistencia · ${editando.nombre}` : 'Nueva resistencia'"
        :guardando="isSubmitting"
        @guardar="guardar"
      >
        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="f'c (kg/cm²)"
          para="fc_kg_cm2"
          :error="errors.fc_kg_cm2"
          obligatorio
          ancho="medio"
        >
          <!-- InputNumber no pasa los atributos al elemento que recibe el foco. -->
          <InputNumber
            v-model="fc"
            v-bind="fcAttrs"
            input-id="fc_kg_cm2"
            :min="1"
            :use-grouping="false"
            :invalid="!!errors.fc_kg_cm2"
            :pt="{ pcInputText: { root: { 'aria-describedby': idError } } }"
            fluid
          />
        </CampoFormulario>
      </DialogoFormulario>
    </template>
  </PaginaListado>
</template>
