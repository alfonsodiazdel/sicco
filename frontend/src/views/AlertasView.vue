<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import PaginaListado from '@/components/plantillas/PaginaListado.vue'
import TablaCrud from '@/components/organismos/TablaCrud.vue'
import { alertasServicio, fechaHora, ubicacion } from '@/services/alertas'
import { decimales } from '@/services/presupuesto'
import { mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { ROLES_ALERTAS, useAlertasStore } from '@/stores/alertas'
import type { Alerta, EstadoAlerta } from '@/types/alertas'

const toast = useToast()
const auth = useAuthStore()
const contador = useAlertasStore()

// El administrador las consulta, pero solo quien las recibe las marca como revisadas.
const puedeRevisar = computed(() => auth.tieneRol(...ROLES_ALERTAS))

// PrimeVue no muestra una opción con valor null: «Todas» tiene su propio valor.
const estados: { valor: EstadoAlerta | 'todas'; nombre: string }[] = [
  { valor: 'todas', nombre: 'Todas' },
  { valor: 'pendiente', nombre: 'Pendientes' },
  { valor: 'revisada', nombre: 'Revisadas' },
]

const estado = ref<EstadoAlerta | 'todas'>('todas')
const alertas = ref<Alerta[]>([])
const cargando = ref(true)
const seleccionada = ref<Alerta | null>(null)
const revisando = ref(false)

const columnas = [
  { campo: 'fecha', encabezado: 'Fecha' },
  { campo: 'proyecto', encabezado: 'Proyecto', secundaria: true },
  { campo: 'sector', encabezado: 'Sector' },
  { campo: 'exceso_m3', encabezado: 'Exceso m³', numerica: true },
  { campo: 'estado', encabezado: 'Estado' },
]

// El aviso del prototipo: la última alerta que sigue pendiente.
const ultimaPendiente = computed(() => alertas.value.find((a) => a.estado === 'pendiente') ?? null)

let ultimaConsulta = 0

async function consultar() {
  const numero = ++ultimaConsulta
  cargando.value = true
  try {
    const resultado = await alertasServicio.listar({
      estado: estado.value === 'todas' ? undefined : estado.value,
    })
    if (numero === ultimaConsulta) alertas.value = resultado
  } catch (error) {
    if (numero === ultimaConsulta) {
      toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
    }
  } finally {
    if (numero === ultimaConsulta) cargando.value = false
  }
}

/** CU14 paso 5: marca la alerta como «Revisada». */
async function revisar(alerta: Alerta) {
  revisando.value = true
  try {
    const respuesta = await alertasServicio.revisar(alerta.id)
    reemplazar(respuesta.alerta)
    toast.add({ severity: 'success', summary: respuesta.message, life: 4000 })
    seleccionada.value = null
  } catch (error) {
    toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
    // Otro usuario pudo revisarla antes: se trae el estado actual.
    await consultar()
    seleccionada.value = null
  } finally {
    revisando.value = false
    contador.actualizar()
  }
}

function reemplazar(alerta: Alerta) {
  alertas.value =
    estado.value === 'pendiente'
      ? alertas.value.filter((a) => a.id !== alerta.id)
      : alertas.value.map((a) => (a.id === alerta.id ? alerta : a))
}

watch(estado, consultar)
onMounted(() => {
  consultar()
  contador.actualizar()
})
</script>

<template>
  <PaginaListado
    titulo="Alertas de consumo"
    subtitulo="Sectores que superaron lo presupuestado más su factor de desperdicio"
  >
    <template #herramientas>
      <div class="flex flex-col gap-1 sm:w-60">
        <label for="estado" class="text-sm font-medium">Estado</label>
        <Select
          v-model="estado"
          input-id="estado"
          :options="estados"
          option-label="nombre"
          option-value="valor"
          fluid
        />
      </div>
    </template>

    <Message
      v-if="ultimaPendiente"
      severity="error"
      icon="pi pi-exclamation-triangle"
      class="m-3"
      data-aviso
    >
      {{ ubicacion(ultimaPendiente) }} superó su límite en
      {{ decimales(ultimaPendiente.exceso_m3) }} m³
    </Message>

    <TablaCrud
      :filas="alertas"
      :columnas="columnas"
      clave="id"
      :vacio="estado === 'pendiente' ? 'No hay alertas pendientes' : 'No hay alertas de consumo'"
      :cargando="cargando"
      :clase-fila="(a) => (a.estado === 'pendiente' ? 'fila-pendiente' : undefined)"
    >
      <template #col-fecha="{ fila }">
        <span class="whitespace-nowrap">{{ fechaHora(fila.fecha) }}</span>
      </template>
      <template #col-proyecto="{ fila }">
        <span>{{ fila.proyecto.codigo }}</span>
        <span class="block text-sm text-surface-500">{{ fila.proyecto.nombre }}</span>
      </template>
      <template #col-sector="{ fila }">
        <span>{{ fila.sector.nivel }} – {{ fila.sector.nombre }}</span>
        <span class="block text-sm text-surface-500">{{ fila.sector.elemento }}</span>
      </template>
      <template #col-exceso_m3="{ fila }">{{ decimales(fila.exceso_m3) }}</template>
      <template #col-estado="{ fila }">
        <div class="flex flex-wrap gap-1">
          <Tag
            :value="fila.estado === 'pendiente' ? 'Pendiente' : 'Revisada'"
            :severity="fila.estado === 'pendiente' ? 'warn' : 'success'"
          />
          <Tag
            v-if="!fila.correo_enviado_at"
            value="Correo por enviar"
            severity="secondary"
            title="El envío se reintenta automáticamente"
          />
        </div>
      </template>
      <template #acciones="{ fila }">
        <Button
          icon="pi pi-eye"
          text
          rounded
          severity="secondary"
          :aria-label="`Ver alerta de ${fila.sector.nivel} – ${fila.sector.nombre}`"
          title="Ver detalle"
          @click="seleccionada = fila"
        />
      </template>
    </TablaCrud>

    <template #dialogos>
      <Dialog
        :visible="seleccionada !== null"
        modal
        header="Alerta de consumo"
        class="w-full max-w-lg"
        @update:visible="(v) => !v && (seleccionada = null)"
      >
        <template v-if="seleccionada">
          <p class="mb-4">
            <span class="font-medium">{{ ubicacion(seleccionada) }}</span>
            del proyecto {{ seleccionada.proyecto.codigo }} {{ seleccionada.proyecto.nombre }}
            superó su límite en
            <span class="font-semibold text-red-700">
              {{ decimales(seleccionada.exceso_m3) }} m³
            </span>
          </p>
          <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1.5 text-sm" data-detalle>
            <dt class="text-surface-500">Fecha</dt>
            <dd>{{ fechaHora(seleccionada.fecha) }}</dd>
            <dt class="text-surface-500">Presupuestado</dt>
            <dd>{{ decimales(seleccionada.sector.presupuestado_m3) }} m³</dd>
            <dt class="text-surface-500">Factor de desperdicio</dt>
            <dd>{{ decimales(seleccionada.sector.factor_desperdicio) }} %</dd>
            <dt class="text-surface-500">Límite</dt>
            <dd>{{ decimales(seleccionada.sector.limite_m3) }} m³</dd>
            <dt class="text-surface-500">Vaciado actual</dt>
            <dd>{{ decimales(seleccionada.sector.vaciado_m3) }} m³</dd>
            <dt class="text-surface-500">Correo</dt>
            <dd>
              {{
                seleccionada.correo_enviado_at
                  ? `Enviado el ${fechaHora(seleccionada.correo_enviado_at)}`
                  : 'Por enviar (se reintenta automáticamente)'
              }}
            </dd>
            <template v-if="seleccionada.estado === 'revisada'">
              <dt class="text-surface-500">Revisada</dt>
              <dd>
                {{ seleccionada.revisada_at ? fechaHora(seleccionada.revisada_at) : '' }}
                <template v-if="seleccionada.revisada_por">
                  por {{ seleccionada.revisada_por }}</template
                >
              </dd>
            </template>
          </dl>
        </template>
        <template #footer>
          <Button label="Cerrar" severity="secondary" text @click="seleccionada = null" />
          <Button
            v-if="puedeRevisar && seleccionada?.estado === 'pendiente'"
            label="Marcar como revisada"
            icon="pi pi-check"
            :loading="revisando"
            @click="revisar(seleccionada!)"
          />
        </template>
      </Dialog>
    </template>
  </PaginaListado>
</template>

<style scoped>
:deep(.fila-pendiente) > td:first-child {
  box-shadow: inset 3px 0 0 var(--p-red-500);
}
</style>
