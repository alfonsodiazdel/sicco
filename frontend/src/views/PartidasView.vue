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
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import DialogoFormulario from '@/components/organismos/DialogoFormulario.vue'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'
import { elementosEstructurales, resistenciasFc } from '@/catalogos/partidas'
import { partidasServicio } from '@/services/partidas'
import { decimales, soles, totalPartida, totalPresupuesto } from '@/services/presupuesto'
import { esquemaPartida, valoresDePartida } from '@/validaciones/partida'
import type { PartidaFila } from '@/types/partidas'
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
const cargando = ref(true)

const total = computed(() => totalPresupuesto(partidas.value))

const columnas = [
  { campo: 'elemento', encabezado: 'Elemento' },
  { campo: 'resistencia_fc', encabezado: "f'c", secundaria: true },
  { campo: 'metrado_m3', encabezado: 'Metrado m³', secundaria: true, numerica: true },
  { campo: 'factor_desperdicio', encabezado: '% Desp.', secundaria: true, numerica: true },
  { campo: 'precio_unitario', encabezado: 'P.U. S/', secundaria: true, numerica: true },
  { campo: 'total', encabezado: 'Total S/', numerica: true },
]

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
    await proyectoActual.cargar()
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

const [elemento, elementoAttrs] = defineField('elemento')
const [resistenciaFc, resistenciaFcAttrs] = defineField('resistencia_fc')
const [metrado, metradoAttrs] = defineField('metrado_m3')
const [desperdicio, desperdicioAttrs] = defineField('factor_desperdicio')
const [precio, precioAttrs] = defineField('precio_unitario')

function abrir(partida: PartidaFila | null = null) {
  editando.value = partida
  resetForm({ values: partida ? valoresDePartida(partida) : {} })
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
  if (valores.factor_desperdicio > 15 && !(await confirmarDesperdicio(valores.factor_desperdicio)))
    return
  try {
    if (editando.value) await partidasServicio.actualizar(editando.value.id, valores)
    else await partidasServicio.crear(proyectoId.value!, valores)
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

    <TablaCrud
      :filas="partidas"
      :columnas="columnas"
      clave="id"
      :vacio="
        proyecto ? 'Este proyecto aún no tiene partidas.' : 'No tiene proyectos activos asignados.'
      "
      :cargando="cargando"
    >
      <template #col-elemento="{ fila }">
        <span>{{ fila.elemento }}</span>
        <span class="block text-sm whitespace-nowrap text-surface-500 sm:hidden">{{
          fila.resistencia_fc
        }}</span>
      </template>
      <template #col-resistencia_fc="{ fila }">
        <span class="whitespace-nowrap">{{ fila.resistencia_fc }}</span>
      </template>
      <template #col-metrado_m3="{ fila }">{{ decimales(fila.metrado_m3) }}</template>
      <template #col-factor_desperdicio="{ fila }"
        >{{ decimales(fila.factor_desperdicio) }} %</template
      >
      <template #col-precio_unitario="{ fila }">{{ decimales(fila.precio_unitario) }}</template>
      <template #col-total="{ fila }">{{ decimales(totalPartida(fila)) }}</template>
      <template v-if="puedeGestionar" #acciones="{ fila }">
        <Button
          icon="pi pi-pencil"
          text
          rounded
          :aria-label="`Editar partida ${fila.elemento}`"
          :title="`Editar partida ${fila.elemento}`"
          @click="abrir(fila)"
        />
      </template>
    </TablaCrud>

    <p class="border-t border-surface-200 p-4 text-right font-semibold">
      Total presupuestado: <span data-total-presupuesto>{{ soles(total) }}</span>
    </p>

    <template #dialogos>
      <DialogoFormulario
        v-model:visible="dialogo"
        :titulo="editando ? 'Editar partida' : `Agregar partida · ${proyecto?.nombre ?? ''}`"
        :guardando="isSubmitting"
        @guardar="guardar"
      >
        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Elemento estructural"
          para="elemento"
          :error="errors.elemento"
          obligatorio
          ancho="medio"
        >
          <!-- Select e InputNumber no pasan los atributos al elemento que recibe el foco. -->
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
          para="resistencia_fc"
          :error="errors.resistencia_fc"
          obligatorio
          ancho="medio"
        >
          <Select
            v-model="resistenciaFc"
            v-bind="resistenciaFcAttrs"
            label-id="resistencia_fc"
            :options="[...resistenciasFc]"
            placeholder="Seleccione"
            :invalid="!!errors.resistencia_fc"
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
          etiqueta="Precio unitario (S/ por m³)"
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
