<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import EncabezadoPagina from '@/components/organismos/EncabezadoPagina.vue'
import { asignacionesServicio } from '@/services/asignaciones'
import { nivelesServicio } from '@/services/niveles'
import { partidasServicio } from '@/services/partidas'
import { decimales } from '@/services/presupuesto'
import { sumaAsignada, validarAsignacion, type FilaAsignacion } from '@/validaciones/asignacion'
import type { GuiaPendiente } from '@/types/guias'
import type { NivelFila, SectorFila } from '@/types/niveles'
import type { PartidaFila } from '@/types/partidas'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { useAlertasStore } from '@/stores/alertas'

const route = useRoute()
const router = useRouter()
const toast = useToast()
const alertas = useAlertasStore()
const auth = useAuthStore()

// El administrador entra en modo consulta: la asignación la guarda Producción.
const puedeAsignar = computed(() => auth.tieneRol('ingeniero_produccion'))

const guia = ref<GuiaPendiente | null>(null)
const niveles = ref<NivelFila[]>([])
const sectores = ref<SectorFila[]>([])
const partidas = ref<PartidaFila[]>([])
const filas = ref<FilaAsignacion[]>([])
const errores = ref<Record<string, string>>({})
// Sectores que ya tiene la guía: si luego se culminaron, el mismo reparto se puede volver a guardar.
const sectoresGuardados = ref<number[]>([])
const cargando = ref(true)
const guardando = ref(false)

const asignado = computed(() => sumaAsignada(filas.value))
const disponible = computed(() =>
  guia.value
    ? (Math.round(guia.value.volumen_m3 * 100) - Math.round(asignado.value * 100)) / 100
    : 0,
)

const partidaDe = (s: SectorFila) => partidas.value.find((p) => p.id === s.presupuesto_id)

/** CU09 paso 2: los sectores agrupados por nivel, de abajo hacia arriba. */
const opcionesSector = computed(() =>
  [...niveles.value]
    .sort((a, b) => a.orden - b.orden || a.id - b.id)
    .map((n) => ({
      nivel: n.nombre,
      sectores: sectores.value
        .filter((s) => s.nivel_id === n.id)
        .map((s) => {
          const p = partidaDe(s)
          const etiqueta = p ? `${s.nombre} · ${p.elemento} · ${p.resistencia_fc}` : s.nombre
          // CU05: un sector culminado ya no recibe guías.
          return {
            id: s.id,
            etiqueta: s.fecha_culminacion ? `${etiqueta} (culminado)` : etiqueta,
            culminado: !!s.fecha_culminacion && !sectoresGuardados.value.includes(s.id),
          }
        }),
    }))
    .filter((g) => g.sectores.length),
)

const sector = (id: number | null) => sectores.value.find((s) => s.id === id)

/** La resistencia de la partida del sector no es la de la guía: se avisa, no se impide. */
function otraResistencia(id: number | null) {
  const s = sector(id)
  const p = s && partidaDe(s)
  return !!p && !!guia.value && p.resistencia_id !== guia.value.resistencia.id
}

const formatoFecha = (iso: string) => iso.split('-').reverse().join('/')

function agregarFila() {
  // Propone lo que falta para completar la guía (CU09 paso 4).
  filas.value.push({ sector_id: null, volumen_m3: disponible.value > 0 ? disponible.value : null })
}

function quitarFila(i: number) {
  filas.value.splice(i, 1)
  errores.value = {}
}

async function cargar() {
  const id = Number(route.params.id)
  const datos = await asignacionesServicio.obtener(id)
  const proyectoId = datos.guia.proyecto_id
  const [n, s, p] = await Promise.all([
    nivelesServicio.listarNiveles(proyectoId),
    nivelesServicio.listarSectores(proyectoId),
    partidasServicio.listar(proyectoId),
  ])
  guia.value = datos.guia
  niveles.value = n
  sectores.value = s
  partidas.value = p
  sectoresGuardados.value = datos.asignaciones.map((a) => a.sector_id)
  filas.value = datos.asignaciones.map((a) => ({
    sector_id: a.sector_id,
    volumen_m3: a.volumen_m3,
  }))
  if (!filas.value.length && sectores.value.length) agregarFila()
}

async function guardar() {
  if (!guia.value) return
  errores.value = validarAsignacion(filas.value, guia.value.volumen_m3)
  if (Object.keys(errores.value).length) return

  guardando.value = true
  try {
    const datos = filas.value.map((f) => ({ sector_id: f.sector_id!, volumen_m3: f.volumen_m3! }))
    const respuesta = await asignacionesServicio.guardar(guia.value.id, datos)
    toast.add({
      severity: 'success',
      summary: respuesta.message ?? 'Guía asignada correctamente',
      life: 4000,
    })
    // CU09 flujo alternativo 2 → CU14: el backend ya registró la alerta de consumo y encoló el
    // correo al ingeniero responsable y a gerencia.
    const nuevas = respuesta.alertas_registradas ?? 0
    for (const s of respuesta.sectores_excedidos ?? []) {
      toast.add({
        severity: 'warn',
        summary: `El ${s.nombre} supera lo presupuestado más el desperdicio`,
        detail: nuevas
          ? 'Se registró la alerta de consumo y se avisará por correo al ingeniero responsable y a gerencia.'
          : undefined,
        life: 8000,
      })
    }
    if (nuevas) alertas.actualizar()
    router.push({ name: 'guias-sin-sector' })
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) errores.value = campos
    else toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  } finally {
    guardando.value = false
  }
}

onMounted(async () => {
  try {
    await cargar()
  } catch (error) {
    toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  } finally {
    cargando.value = false
  }
})

const numero = { locale: 'es-PE', minFractionDigits: 0, maxFractionDigits: 2, min: 0, fluid: true }
</script>

<template>
  <section class="space-y-4">
    <EncabezadoPagina
      :titulo="guia ? `Asignar guía ${guia.numero}` : 'Asignar guía'"
      subtitulo="Reparta el volumen de la guía entre los sectores donde se vació"
    >
      <template #acciones>
        <Button
          label="Volver"
          icon="pi pi-arrow-left"
          text
          @click="router.push({ name: 'guias-sin-sector' })"
        />
      </template>
    </EncabezadoPagina>

    <p v-if="cargando" class="text-surface-500">Cargando…</p>

    <template v-else-if="guia">
      <!-- CU09 paso 1: datos de la guía y volumen disponible. -->
      <dl
        class="grid grid-cols-2 gap-4 rounded-lg border border-surface-200 bg-white p-4 sm:grid-cols-5"
        aria-label="Datos de la guía"
      >
        <div>
          <dt class="text-sm text-surface-500">Fecha</dt>
          <dd class="font-medium">{{ formatoFecha(guia.fecha) }}</dd>
        </div>
        <div>
          <dt class="text-sm text-surface-500">Proveedor</dt>
          <dd class="font-medium">{{ guia.proveedor.razon_social }}</dd>
        </div>
        <div>
          <dt class="text-sm text-surface-500">Resistencia</dt>
          <dd class="font-medium">{{ guia.resistencia.nombre }}</dd>
        </div>
        <div>
          <dt class="text-sm text-surface-500">Volumen de la guía</dt>
          <dd class="font-medium">{{ decimales(guia.volumen_m3) }} m³</dd>
        </div>
        <div>
          <dt class="text-sm text-surface-500">Disponible</dt>
          <dd
            data-disponible
            class="font-semibold"
            :class="disponible < 0 ? 'text-red-700' : 'text-primary-700'"
          >
            {{ decimales(disponible) }} m³
          </dd>
        </div>
      </dl>

      <!-- CU09 flujo alternativo 3. -->
      <Message v-if="!sectores.length" severity="warn" icon="pi pi-exclamation-triangle">
        El proyecto no tiene sectores de vaciado. Primero regístrelos en
        <RouterLink to="/niveles" class="font-semibold underline">Niveles y sectores</RouterLink>.
      </Message>

      <form
        v-else
        class="space-y-3 rounded-lg border border-surface-200 bg-white p-4"
        novalidate
        @submit.prevent="guardar"
      >
        <h3 class="font-semibold">Sectores de vaciado</h3>

        <div
          v-for="(fila, i) in filas"
          :key="i"
          class="grid grid-cols-1 gap-2 border-b border-surface-100 pb-3 sm:grid-cols-[1fr_10rem_auto] sm:items-start"
          data-fila
        >
          <div class="flex flex-col gap-1">
            <label :for="`sector-${i}`" class="text-sm font-medium">Sector</label>
            <Select
              v-model="fila.sector_id"
              :label-id="`sector-${i}`"
              :options="opcionesSector"
              option-group-label="nivel"
              option-group-children="sectores"
              option-label="etiqueta"
              option-value="id"
              option-disabled="culminado"
              placeholder="Seleccione"
              :disabled="!puedeAsignar"
              :invalid="!!errores[`asignaciones.${i}.sector_id`]"
              fluid
            />
            <small v-if="errores[`asignaciones.${i}.sector_id`]" class="text-red-600">{{
              errores[`asignaciones.${i}.sector_id`]
            }}</small>
            <span v-if="sector(fila.sector_id)" class="text-sm text-surface-500">
              Presupuestado {{ decimales(sector(fila.sector_id)!.volumen_presup_m3) }} m³ · vaciado
              {{ decimales(sector(fila.sector_id)!.volumen_vaciado_m3) }} m³
              <Tag
                v-if="otraResistencia(fila.sector_id)"
                value="f'c distinto al de la guía"
                severity="warn"
                class="ml-1"
              />
            </span>
          </div>
          <div class="flex flex-col gap-1">
            <label :for="`volumen-${i}`" class="text-sm font-medium">Volumen vaciado (m³)</label>
            <InputNumber
              v-model="fila.volumen_m3"
              v-bind="numero"
              :input-id="`volumen-${i}`"
              :disabled="!puedeAsignar"
              :invalid="!!errores[`asignaciones.${i}.volumen_m3`]"
            />
            <small v-if="errores[`asignaciones.${i}.volumen_m3`]" class="text-red-600">{{
              errores[`asignaciones.${i}.volumen_m3`]
            }}</small>
          </div>
          <Button
            v-if="puedeAsignar"
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            class="sm:mt-6"
            :aria-label="`Quitar sector ${i + 1}`"
            :disabled="filas.length === 1"
            @click="quitarFila(i)"
          />
        </div>

        <p v-if="errores.asignaciones" class="text-red-600" role="alert">
          {{ errores.asignaciones }}
        </p>

        <div v-if="puedeAsignar" class="flex flex-col gap-2 sm:flex-row sm:justify-between">
          <Button
            label="Agregar sector"
            icon="pi pi-plus"
            outlined
            :disabled="disponible <= 0"
            @click="agregarFila"
          />
          <Button
            type="submit"
            label="Guardar asignación"
            icon="pi pi-check"
            :loading="guardando"
          />
        </div>
      </form>
    </template>
  </section>
</template>
