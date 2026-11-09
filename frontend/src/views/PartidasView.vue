<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaPresupuesto from '@/components/organismos/TablaPresupuesto.vue'
import DialogoFormulario from '@/components/organismos/DialogoFormulario.vue'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'
import { elementosEstructurales } from '@/catalogos/partidas'
import { gruposServicio } from '@/services/grupos'
import { partidasServicio } from '@/services/partidas'
import { decimales } from '@/services/presupuesto'
import { resistenciasServicio } from '@/services/resistencias'
import { esquemaPartida, valoresDePartida } from '@/validaciones/partida'
import type { GrupoFila } from '@/types/grupos'
import type { PartidaFila } from '@/types/partidas'
import type { Resistencia } from '@/types/resistencias'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'

const toast = useToast()
const confirm = useConfirm()
const auth = useAuthStore()
const proyectoActual = useProyectoActualStore()

// El administrador entra en modo consulta: registrar y editar quedan para Oficina técnica.
const puedeGestionar = computed(() => auth.tieneRol('oficina_tecnica'))

// El proyecto se elige en el encabezado (SelectorProyecto).
const { proyecto, proyectoId } = storeToRefs(proyectoActual)
const partidas = ref<PartidaFila[]>([])
const grupos = ref<GrupoFila[]>([])
const resistencias = ref<Resistencia[]>([])
const cargando = ref(true)

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

async function cargarPartidas() {
  if (!proyectoActual.cargado) return
  const id = proyectoId.value
  cargando.value = true
  try {
    const filas = id ? await partidasServicio.listar(id) : []
    // Si se eligió otro proyecto mientras cargaba, esta respuesta ya no aplica.
    if (id !== proyectoId.value) return
    partidas.value = filas
  } catch (error) {
    if (id !== proyectoId.value) return
    partidas.value = []
    avisarError(error)
  }
  cargando.value = false
}

// Al tener la lista de proyectos y cada vez que se elige otro en el encabezado.
watch(() => proyectoActual.cargado && proyectoId.value, cargarPartidas, { immediate: true })

onMounted(async () => {
  try {
    // Los catálogos de grupos y resistencias son los mismos para todos los proyectos: se piden una vez.
    const [lista, fc] = await Promise.all([
      gruposServicio.listar(),
      resistenciasServicio.listar(),
      proyectoActual.cargar(),
    ])
    grupos.value = lista
    resistencias.value = fc
  } catch (error) {
    avisarError(error)
    cargando.value = false
  }
})

const dialogo = ref(false)
const editando = ref<PartidaFila | null>(null)

const { defineField, handleSubmit, errors, setErrors, resetForm, isSubmitting } = useForm({
  validationSchema: toTypedSchema(esquemaPartida),
})

const [grupoId, grupoIdAttrs] = defineField('grupo_id')
const [item, itemAttrs] = defineField('item')
const [descripcion, descripcionAttrs] = defineField('descripcion')
const [elemento, elementoAttrs] = defineField('elemento')
const [resistenciaId, resistenciaIdAttrs] = defineField('resistencia_id')
const [metrado, metradoAttrs] = defineField('metrado_m3')
const [desperdicio, desperdicioAttrs] = defineField('factor_desperdicio')
const [precio, precioAttrs] = defineField('precio_unitario')

function abrir(partida: PartidaFila | null = null) {
  editando.value = partida
  resetForm({ values: partida ? valoresDePartida(partida) : {} }, { force: true })
  dialogo.value = true
}

const confirmarDesperdicio = (valor: number) =>
  new Promise<boolean>((resolver) =>
    confirm.require({
      header: 'Confirmar desperdicio',
      message: `El factor de desperdicio es ${decimales(valor)} %, mayor a 15 %. ¿Confirma el valor?`,
      acceptLabel: 'Confirmar',
      rejectLabel: 'Cancelar',
      accept: () => resolver(true),
      reject: () => resolver(false),
      onHide: () => resolver(false),
    }),
  )

const guardar = handleSubmit(async (valores) => {
  const confirmado = valores.factor_desperdicio > 15
  if (confirmado && !(await confirmarDesperdicio(valores.factor_desperdicio))) return
  try {
    if (editando.value) await partidasServicio.actualizar(editando.value.id, valores, confirmado)
    else await partidasServicio.crear(proyectoId.value!, valores, confirmado)
    toast.add({
      severity: 'success',
      summary: editando.value ? 'Cambios guardados.' : 'Partida registrada',
      life: 4000,
    })
    dialogo.value = false
    await cargarPartidas()
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) setErrors(campos)
    else toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  }
})

const numero = { locale: 'es-PE', minFractionDigits: 0, maxFractionDigits: 2, min: 0, fluid: true }
</script>

<template>
  <PaginaListado
    :titulo="proyecto ? `Presupuesto – ${proyecto.nombre}` : 'Presupuesto del proyecto'"
    subtitulo="Volúmenes en m³ e importes en soles, con dos decimales"
  >
    <template v-if="puedeGestionar" #acciones>
      <Button
        label="Agregar partida"
        icon="pi pi-plus"
        aria-label="Agregar partida"
        :disabled="!proyecto"
        @click="abrir()"
      />
    </template>

    <TablaPresupuesto
      :partidas="partidas"
      :grupos="grupos"
      :vacio="
        proyecto ? 'Este proyecto aún no tiene partidas.' : 'No tiene proyectos activos asignados.'
      "
      :cargando="cargando"
    >
      <template v-if="puedeGestionar" #acciones="{ fila }">
        <Button
          icon="pi pi-pencil"
          text
          rounded
          class="max-sm:w-8!"
          :aria-label="`Editar partida ${fila.descripcion}`"
          :title="`Editar partida ${fila.descripcion}`"
          @click="abrir(fila)"
        />
      </template>
    </TablaPresupuesto>

    <template #dialogos>
      <DialogoFormulario
        v-model:visible="dialogo"
        :titulo="editando ? 'Editar partida' : `Agregar partida · ${proyecto?.nombre ?? ''}`"
        :guardando="isSubmitting"
        @guardar="guardar"
      >
        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Grupo"
          para="grupo_id"
          :error="errors.grupo_id"
          obligatorio
        >
          <!-- Select e InputNumber no pasan los atributos al elemento que recibe el foco. -->
          <Select
            v-model="grupoId"
            v-bind="grupoIdAttrs"
            label-id="grupo_id"
            :options="grupos"
            option-label="nombre"
            option-value="id"
            placeholder="Seleccione"
            :invalid="!!errors.grupo_id"
            :pt="{ label: { 'aria-describedby': idError } }"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Ítem del expediente"
          para="item"
          :error="errors.item"
          ancho="medio"
        >
          <InputText
            id="item"
            v-model="item"
            v-bind="itemAttrs"
            maxlength="20"
            placeholder="Ej.: 03.01.01.01"
            :invalid="!!errors.item"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Descripción"
          para="descripcion"
          :error="errors.descripcion"
          obligatorio
        >
          <InputText
            id="descripcion"
            v-model="descripcion"
            v-bind="descripcionAttrs"
            maxlength="200"
            placeholder="Ej.: CONCRETO F'C=210 kg/cm2 - ZAPATAS"
            :invalid="!!errors.descripcion"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Elemento estructural"
          para="elemento"
          :error="errors.elemento"
          obligatorio
          ancho="medio"
        >
          <Select
            v-model="elemento"
            v-bind="elementoAttrs"
            label-id="elemento"
            :options="[...elementosEstructurales]"
            placeholder="Seleccione"
            :invalid="!!errors.elemento"
            :pt="{ label: { 'aria-describedby': idError } }"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Resistencia f'c"
          para="resistencia_id"
          :error="errors.resistencia_id"
          obligatorio
          ancho="medio"
        >
          <Select
            v-model="resistenciaId"
            v-bind="resistenciaIdAttrs"
            label-id="resistencia_id"
            :options="resistencias"
            option-label="nombre"
            option-value="id"
            placeholder="Seleccione"
            :invalid="!!errors.resistencia_id"
            :pt="{ label: { 'aria-describedby': idError } }"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Metrado (m³)"
          para="metrado_m3"
          :error="errors.metrado_m3"
          obligatorio
          ancho="medio"
        >
          <InputNumber
            v-model="metrado"
            v-bind="{ ...metradoAttrs, ...numero }"
            input-id="metrado_m3"
            placeholder="Ej.: 245.50"
            :invalid="!!errors.metrado_m3"
            :pt="{ pcInputText: { root: { 'aria-describedby': idError } } }"
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Factor de desperdicio (%)"
          para="factor_desperdicio"
          :error="errors.factor_desperdicio"
          obligatorio
          ancho="medio"
        >
          <InputNumber
            v-model="desperdicio"
            v-bind="{ ...desperdicioAttrs, ...numero }"
            input-id="factor_desperdicio"
            placeholder="Ej.: 5"
            :invalid="!!errors.factor_desperdicio"
            :pt="{ pcInputText: { root: { 'aria-describedby': idError } } }"
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Precio unitario sin IGV (S/ por m³)"
          para="precio_unitario"
          :error="errors.precio_unitario"
          obligatorio
        >
          <InputNumber
            v-model="precio"
            v-bind="{ ...precioAttrs, ...numero }"
            input-id="precio_unitario"
            placeholder="Ej.: 390.00"
            :invalid="!!errors.precio_unitario"
            :pt="{ pcInputText: { root: { 'aria-describedby': idError } } }"
          />
        </CampoFormulario>
      </DialogoFormulario>
    </template>
  </PaginaListado>
</template>
