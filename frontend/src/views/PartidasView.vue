<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
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
import { proyectosServicio } from '@/services/proyectos'
import { decimales, soles, totalPartida, totalPresupuesto } from '@/services/presupuesto'
import { esquemaPartida, valoresDePartida } from '@/validaciones/partida'
import type { PartidaFila } from '@/types/partidas'
import type { ProyectoFila } from '@/types/proyectos'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'

const toast = useToast()
const confirm = useConfirm()
const route = useRoute()

const proyectos = ref<ProyectoFila[]>([])
const proyectoId = ref<number | null>(null)
const partidas = ref<PartidaFila[]>([])
const cargando = ref(true)

const proyecto = computed(() => proyectos.value.find((p) => p.id === proyectoId.value))
const total = computed(() => totalPresupuesto(partidas.value))

const columnas = [
  { campo: 'elemento', encabezado: 'Elemento' },
  { campo: 'resistencia_fc', encabezado: "f'c", secundaria: true },
  { campo: 'metrado_m3', encabezado: 'Metrado m³', secundaria: true, numerica: true },
  { campo: 'factor_desperdicio', encabezado: '% Desp.', secundaria: true, numerica: true },
  { campo: 'precio_unitario', encabezado: 'P.U. S/', secundaria: true, numerica: true },
  { campo: 'total', encabezado: 'Total S/', numerica: true },
]

async function cargarPartidas() {
  partidas.value = proyectoId.value ? await partidasServicio.listar(proyectoId.value) : []
}

watch(proyectoId, async () => {
  try {
    await cargarPartidas()
  } catch (error) {
    toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  }
})

onMounted(async () => {
  try {
    proyectos.value = (await proyectosServicio.listar()).filter((p) => p.estado === 'Activo')
    // Desde Proyectos llega ?proyecto=<id>; solo se respeta si es uno de los activos.
    const pedido = proyectos.value.find((p) => String(p.id) === route.query.proyecto)
    proyectoId.value = (pedido ?? proyectos.value[0])?.id ?? null
  } catch (error) {
    toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  } finally {
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
    titulo="Presupuesto de concreto"
    subtitulo="Volúmenes en m³ e importes en soles, con dos decimales"
  >
    <template #acciones>
      <Button
        label="Agregar partida"
        icon="pi pi-plus"
        aria-label="Agregar partida"
        :disabled="!proyecto"
        @click="abrir()"
      />
    </template>

    <template #herramientas>
      <Select
        v-model="proyectoId"
        :options="proyectos"
        option-label="nombre"
        option-value="id"
        placeholder="Seleccione un proyecto"
        aria-label="Proyecto"
        class="w-full sm:w-80"
      />
    </template>

    <TablaCrud
      :filas="partidas"
      :columnas="columnas"
      clave="id"
      vacio="Este proyecto aún no tiene partidas."
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
      <template #acciones="{ fila }">
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
