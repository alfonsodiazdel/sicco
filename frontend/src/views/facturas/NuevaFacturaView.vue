<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import EncabezadoPagina from '@/components/organismos/EncabezadoPagina.vue'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'
import DialogoVincularGuias from '@/components/organismos/DialogoVincularGuias.vue'
import { facturasServicio } from '@/services/facturas'
import { comprimirFoto } from '@/services/imagen'
import { resistenciasServicio } from '@/services/resistencias'
import { decimales, soles } from '@/services/presupuesto'
import {
  ARCHIVO_INVALIDO,
  compararVolumenes,
  igvSugerido,
  importe,
  MAXIMO_ARCHIVO,
  subtotalFactura,
  subtotalLineas,
  sugerirGuias,
  sumar,
  TIPOS_ARCHIVO,
  validarFactura,
  volumenesCoinciden,
  type FilaCargo,
  type FilaLinea,
} from '@/validaciones/factura'
import { hoy } from '@/validaciones/guia'
import { erroresDeValidacion, esErrorApi, mensajeDeError } from '@/api/http'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import type { CampoLecturaFactura, LecturaFactura } from '@/types/facturas'
import type { GuiaFila } from '@/types/guias'
import type { Proveedor } from '@/types/proveedor'
import type { Resistencia } from '@/types/resistencias'

const router = useRouter()
const toast = useToast()
const confirm = useConfirm()
const proyectoActual = useProyectoActualStore()
// CU11 paso 1: el proyecto es el elegido en el encabezado (SelectorProyecto).
const { proyecto, proyectoId } = storeToRefs(proyectoActual)

const proveedores = ref<Proveedor[]>([])
const resistencias = ref<Resistencia[]>([])

/** Primero el archivo (se lee con OCR) y luego los datos para revisar o digitar. */
const paso = ref<'archivo' | 'datos'>('archivo')
const archivo = ref<File | null>(null)
const preparandoArchivo = ref(false)
/** El archivo ya subido en la lectura: el registro lo usa sin volver a enviarlo. */
const archivoTemporal = ref<string | null>(null)
const lectura = ref<LecturaFactura | null>(null)
/** El OCR no respondió o no reconoció la factura: se ingresa a mano. */
const sinLectura = ref(false)
const leyendo = ref(false)

const numero = ref('')
const fecha = ref(hoy())
const proveedorId = ref<number | null>(null)
const lineaVacia = (): FilaLinea => ({
  resistencia_id: null,
  volumen_m3: null,
  precio_unitario: null,
})
const lineas = ref<FilaLinea[]>([lineaVacia()])
/** Lo leído de cada línea, para avisar las que deben revisarse. */
const lineasLeidas = ref<{ texto: string; revisar: boolean }[]>([])
const cargos = ref<(FilaCargo & { revisar?: boolean })[]>([])
const igv = ref<number | null>(0)
/** El IGV se propone al 18 % del subtotal hasta que el usuario lo cambia o lo trae la lectura. */
const igvEditado = ref(false)

/** CU12: guías vinculadas, elegidas en el diálogo o propuestas tras la lectura. */
const vinculadas = ref<GuiaFila[]>([])
const pendientes = ref<GuiaFila[]>([])
const dialogoGuias = ref(false)
const cargandoGuias = ref(false)
/** Cómo se propusieron las guías y las citadas que no se pudieron proponer. */
const propuesta = ref<{ origen: 'citadas' | 'volumen' | null; avisos: string[] }>({
  origen: null,
  avisos: [],
})

const errores = ref<Record<string, string>>({})
const guardando = ref(false)

const subtotalConcreto = computed(() => subtotalLineas(lineas.value))
const subtotalCargos = computed(() => sumar(...cargos.value.map((c) => c.importe ?? 0)))
const subtotal = computed(() => subtotalFactura(lineas.value, cargos.value))
const total = computed(() => sumar(subtotal.value, igv.value ?? 0))
const volumenFacturado = computed(() => sumar(...lineas.value.map((l) => l.volumen_m3 ?? 0)))
const volumenGuias = computed(() => sumar(...vinculadas.value.map((g) => g.volumen_m3)))

/** El total que imprime la factura, si se leyó: el calculado debe coincidir. */
const totalLeido = computed(() => {
  const valor = lectura.value?.campos.total.valor
  return typeof valor === 'number' ? valor : null
})
const totalDistinto = computed(
  () =>
    totalLeido.value !== null &&
    Math.round(totalLeido.value * 100) !== Math.round(total.value * 100),
)

watch(subtotal, (valor) => {
  if (!igvEditado.value) igv.value = igvSugerido(valor)
})

function cambiarIgv(valor: number | null) {
  igv.value = valor
  igvEditado.value = true
}

/** Precio de cada resistencia en la factura: da el importe de las guías (CU12 paso 3). */
const precios = computed(
  () =>
    new Map(
      lineas.value
        .filter((l) => l.resistencia_id !== null && l.precio_unitario)
        .map((l) => [l.resistencia_id!, l.precio_unitario!]),
    ),
)
const importeDeGuia = (g: GuiaFila) => {
  const precio = precios.value.get(g.resistencia.id)
  return precio ? importe(g.volumen_m3, precio) : null
}
const importeGuias = computed(() => sumar(...vinculadas.value.map((g) => importeDeGuia(g) ?? 0)))

/** CU11 paso 5: el volumen de cada resistencia en la factura frente al de las guías. */
const comparacion = computed(() => compararVolumenes(lineas.value, vinculadas.value))
const coinciden = computed(() => volumenesCoinciden(comparacion.value))
const nombreResistencia = (id: number) =>
  resistencias.value.find((r) => r.id === id)?.nombre ??
  vinculadas.value.find((g) => g.resistencia.id === id)?.resistencia.nombre ??
  ''

const opcionesProveedor = computed(() =>
  proveedores.value.map((p) => ({ id: p.id, etiqueta: `${p.razon_social} · RUC ${p.ruc}` })),
)

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

onMounted(async () => {
  try {
    await Promise.all([
      proyectoActual.cargar(),
      facturasServicio.proveedores().then((p) => (proveedores.value = p)),
      resistenciasServicio.listar().then((r) => (resistencias.value = r)),
    ])
  } catch (error) {
    avisarError(error)
  }
})

function limpiarGuias() {
  vinculadas.value = []
  pendientes.value = []
  propuesta.value = { origen: null, avisos: [] }
}

// Las guías vinculadas son del proveedor y proyecto: si cambian, se vuelven a elegir.
watch(proyectoId, limpiarGuias)

function cambiarProveedor(id: number | null) {
  if (id === proveedorId.value) return
  proveedorId.value = id
  limpiarGuias()
}

/** CU11 paso 1: el archivo de la factura. Una foto se reduce antes de subirla, como la guía. */
async function elegirArchivo(evento: Event) {
  const entrada = evento.target as HTMLInputElement
  const elegido = entrada.files?.[0] ?? null
  entrada.value = ''
  if (!elegido) return
  errores.value = { ...errores.value, archivo: '' }
  if (!TIPOS_ARCHIVO.includes(elegido.type) || elegido.size > MAXIMO_ARCHIVO) {
    archivo.value = elegido
    errores.value.archivo = ARCHIVO_INVALIDO
    return
  }
  preparandoArchivo.value = true
  try {
    const reducido = elegido.type.startsWith('image/') ? await comprimirFoto(elegido) : elegido
    archivo.value =
      reducido instanceof File
        ? reducido
        : new File([reducido], elegido.name.replace(/\.\w+$/, '.jpg'), { type: reducido.type })
    archivoTemporal.value = null
  } finally {
    preparandoArchivo.value = false
  }
}

/** Lo leído de la cabecera, para avisar los datos que deben revisarse hasta que se corrigen. */
const leidos = ref<Partial<Record<CampoLecturaFactura, string | number | null>>>({})

function aviso(campo: CampoLecturaFactura, actual: string | number | null) {
  const l = lectura.value
  if (!l || actual !== (leidos.value[campo] ?? null)) return undefined
  const leido = l.campos[campo]
  if (campo === 'proveedor') {
    if (actual !== null) return leido.revisar ? 'Verifique este dato' : undefined
    const { proveedor, ruc } = l.campos
    return proveedor.valor || ruc.valor
      ? `Se leyó «${[proveedor.valor, ruc.valor && `RUC ${ruc.valor}`].filter(Boolean).join(' · ')}», que no está registrado; elija el proveedor`
      : 'No se pudo leer; elija el proveedor'
  }
  if (leido.valor === null) return 'No se pudo leer; ingrese este dato'
  return leido.revisar ? 'Verifique este dato' : undefined
}

/** Vuelca lo leído en el formulario para revisarlo (CU11 con OCR). */
function llenar(l: LecturaFactura) {
  const texto = (campo: CampoLecturaFactura) =>
    l.campos[campo].valor === null ? '' : String(l.campos[campo].valor)
  numero.value = texto('numero')
  fecha.value = texto('fecha') || hoy()
  proveedorId.value = l.proveedor_id
  leidos.value = {
    numero: numero.value,
    fecha: texto('fecha') ? fecha.value : null,
    proveedor: l.proveedor_id,
  }
  lineas.value = l.lineas.length
    ? l.lineas.map((x) => ({
        resistencia_id: x.resistencia_id,
        volumen_m3: x.volumen_m3,
        precio_unitario: x.precio_unitario,
      }))
    : [lineaVacia()]
  lineasLeidas.value = l.lineas.map((x) => ({
    texto: [x.descripcion, x.codigo_producto].filter(Boolean).join(' · '),
    revisar: x.revisar,
  }))
  cargos.value = l.cargos.map((c) => ({
    descripcion: c.descripcion ?? '',
    importe: c.importe,
    revisar: c.revisar,
  }))
  const igvLeido = l.campos.igv.valor
  igvEditado.value = typeof igvLeido === 'number'
  igv.value = typeof igvLeido === 'number' ? igvLeido : igvSugerido(subtotal.value)
}

function formularioVacio() {
  numero.value = ''
  fecha.value = hoy()
  proveedorId.value = null
  leidos.value = {}
  lineas.value = [lineaVacia()]
  lineasLeidas.value = []
  cargos.value = []
  igvEditado.value = false
  igv.value = 0
  limpiarGuias()
}

/**
 * CU12 tras la lectura: se marcan las guías que cita la factura; si no cita ninguna, las
 * pendientes que completan el volumen facturado de cada resistencia, de la más antigua.
 */
async function proponerGuias(l: LecturaFactura) {
  if (!proyectoId.value || !proveedorId.value) return
  try {
    pendientes.value = await facturasServicio.guiasPorFacturar(proyectoId.value, proveedorId.value)
  } catch {
    return
  }
  const citadas = l.guias.sugeridas.length > 0 || l.guias.avisos.length > 0
  const ids = citadas ? l.guias.sugeridas : sugerirGuias(pendientes.value, lineas.value)
  vinculadas.value = pendientes.value.filter((g) => ids.includes(g.id))
  propuesta.value = {
    origen: vinculadas.value.length ? (citadas ? 'citadas' : 'volumen') : null,
    avisos: l.guias.avisos,
  }
}

/** CU11 paso 1 con OCR. Si el OCR no responde, sigue con el ingreso manual y el archivo adjunto. */
async function leer() {
  if (!archivo.value || !proyectoId.value) return
  leyendo.value = true
  try {
    const respuesta = await facturasServicio.leer(proyectoId.value, archivo.value)
    archivoTemporal.value = respuesta.archivo_temporal
    lectura.value = respuesta.lectura
    sinLectura.value = !respuesta.lectura
    formularioVacio()
    if (respuesta.lectura) {
      llenar(respuesta.lectura)
      await proponerGuias(respuesta.lectura)
    }
    paso.value = 'datos'
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (campos.archivo) {
      errores.value = { ...errores.value, archivo: campos.archivo }
      return
    }
    // Sin respuesta del servidor: también se ingresa a mano, con el archivo adjunto.
    lectura.value = null
    sinLectura.value = true
    formularioVacio()
    paso.value = 'datos'
  } finally {
    leyendo.value = false
  }
}

function ingresarManualmente() {
  lectura.value = null
  sinLectura.value = false
  formularioVacio()
  paso.value = 'datos'
}

function agregarLinea() {
  lineas.value.push(lineaVacia())
}

function quitarLinea(i: number) {
  lineas.value.splice(i, 1)
  lineasLeidas.value.splice(i, 1)
  errores.value = {}
}

function agregarCargo() {
  cargos.value.push({ descripcion: '', importe: null })
}

function quitarCargo(i: number) {
  cargos.value.splice(i, 1)
  errores.value = {}
}

/** CU11 paso 3: ejecuta CU12 para vincular las guías de la factura. */
async function abrirVincular() {
  if (!proyectoId.value || !proveedorId.value) {
    errores.value = { ...errores.value, proveedor_id: 'Elija el proveedor para ver sus guías' }
    return
  }
  dialogoGuias.value = true
  cargandoGuias.value = true
  try {
    pendientes.value = await facturasServicio.guiasPorFacturar(proyectoId.value, proveedorId.value)
  } catch (error) {
    dialogoGuias.value = false
    avisarError(error)
  } finally {
    cargandoGuias.value = false
  }
}

function vincular(ids: number[]) {
  vinculadas.value = pendientes.value.filter((g) => ids.includes(g.id))
  propuesta.value = { ...propuesta.value, origen: null }
  errores.value = { ...errores.value, guia_ids: '' }
}

function quitarGuia(id: number) {
  vinculadas.value = vinculadas.value.filter((g) => g.id !== id)
}

const confirmarDiferencia = () =>
  new Promise<boolean>((resolver) =>
    confirm.require({
      header: 'Confirmar factura',
      message:
        'El volumen facturado no coincide con las guías. ¿Registrar la factura de todas formas?',
      icon: 'pi pi-exclamation-triangle',
      acceptLabel: 'Registrar',
      rejectLabel: 'Revisar',
      accept: () => resolver(true),
      reject: () => resolver(false),
      onHide: () => resolver(false),
    }),
  )

const requiereConfirmacion = (error: unknown) =>
  esErrorApi(error) &&
  error.response?.status === 422 &&
  error.response.data?.code === 'requiere_confirmacion'

/** CU11 pasos 4–6. */
async function guardar(confirmado = false) {
  const valores = {
    numero: numero.value,
    fecha: fecha.value,
    proveedor_id: proveedorId.value,
    archivo: archivoTemporal.value ? null : archivo.value,
    archivo_temporal: archivoTemporal.value,
    lineas: lineas.value,
    cargos: cargos.value,
    igv: igv.value,
    guia_ids: vinculadas.value.map((g) => g.id),
  }
  errores.value = validarFactura(valores)
  if (Object.keys(errores.value).length || !proyectoId.value) return

  guardando.value = true
  try {
    const respuesta = await facturasServicio.registrar({
      proyecto_id: proyectoId.value,
      proveedor_id: proveedorId.value!,
      numero: numero.value.trim(),
      fecha: fecha.value,
      ...(archivoTemporal.value
        ? { archivo_temporal: archivoTemporal.value }
        : { archivo: archivo.value! }),
      lineas: lineas.value.map((l) => ({
        resistencia_id: l.resistencia_id!,
        volumen_m3: l.volumen_m3!,
        precio_unitario: l.precio_unitario!,
      })),
      cargos: cargos.value.map((c) => ({ descripcion: c.descripcion.trim(), importe: c.importe! })),
      igv: igv.value!,
      guia_ids: valores.guia_ids,
      confirmado,
    })
    toast.add({ severity: 'success', summary: respuesta.message, life: 4000 })
    router.push({ name: 'facturas' })
  } catch (error) {
    // CU11 flujo alternativo 1: el volumen no coincide; se registra solo si se confirma.
    if (!confirmado && requiereConfirmacion(error)) {
      guardando.value = false
      if (await confirmarDiferencia()) await guardar(true)
      return
    }
    const campos = erroresDeValidacion(error)
    // El archivo subido en la lectura ya no está (se limpia al día siguiente): se envía de nuevo.
    if ((campos.archivo || campos.archivo_temporal) && archivoTemporal.value && archivo.value) {
      archivoTemporal.value = null
      guardando.value = false
      return guardar(confirmado)
    }
    if (Object.keys(campos).length) errores.value = campos
    else avisarError(error)
  } finally {
    guardando.value = false
  }
}

const formatoFecha = (iso: string) => iso.split('-').reverse().join('/')
const decimal = { locale: 'es-PE', minFractionDigits: 2, maxFractionDigits: 2, min: 0, fluid: true }
const tamano = (bytes: number) =>
  bytes < 1024 * 1024 ? `${Math.ceil(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
</script>

<template>
  <section class="mx-auto max-w-4xl space-y-4">
    <EncabezadoPagina
      titulo="Nueva factura"
      :subtitulo="proyecto ? `Factura de concreto de ${proyecto.nombre}` : 'Factura de concreto'"
    >
      <template #acciones>
        <Button
          label="Volver"
          icon="pi pi-arrow-left"
          text
          @click="router.push({ name: 'facturas' })"
        />
      </template>
    </EncabezadoPagina>

    <Message
      v-if="proyectoActual.cargado && !proyecto"
      severity="warn"
      icon="pi pi-exclamation-triangle"
    >
      No tiene proyectos activos asignados. Pida al administrador que lo asigne a uno.
    </Message>

    <!-- CU11 paso 1: el archivo de la factura, que se lee con OCR. -->
    <div
      v-else-if="paso === 'archivo'"
      class="space-y-4 rounded-lg border border-surface-200 bg-white p-4"
    >
      <label
        for="archivo"
        class="flex min-h-56 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-surface-300 bg-surface-50 p-6 text-center text-surface-600 hover:border-primary hover:text-primary-700"
      >
        <i
          class="pi text-4xl"
          :class="
            preparandoArchivo
              ? 'pi-spin pi-spinner'
              : archivo?.type === 'application/pdf'
                ? 'pi-file-pdf'
                : archivo
                  ? 'pi-image'
                  : 'pi-upload'
          "
          aria-hidden="true"
        />
        <template v-if="archivo">
          <span class="text-base font-medium" data-archivo>{{ archivo.name }}</span>
          <span class="text-sm text-surface-500">{{ tamano(archivo.size) }} · Cambiar archivo</span>
        </template>
        <template v-else>
          <span class="text-base font-medium">Adjuntar factura</span>
          <span class="text-sm text-surface-500"
            >PDF de la factura electrónica o foto, de hasta 10 MB</span
          >
        </template>
      </label>
      <input
        id="archivo"
        type="file"
        accept="image/jpeg,image/png,image/webp,application/pdf"
        class="sr-only"
        :aria-invalid="!!errores.archivo"
        @change="elegirArchivo"
      />
      <p v-if="errores.archivo" class="text-sm text-red-600" role="alert">{{ errores.archivo }}</p>

      <div class="flex flex-col gap-2 sm:flex-row-reverse">
        <Button
          :label="leyendo ? 'Leyendo factura…' : 'Leer factura'"
          icon="pi pi-sparkles"
          class="sm:flex-1"
          :loading="leyendo"
          :disabled="!archivo || !!errores.archivo || !proyecto || preparandoArchivo"
          @click="leer"
        />
        <Button
          label="Ingresar manualmente"
          severity="secondary"
          outlined
          :disabled="leyendo"
          data-ingresar-manual
          @click="ingresarManualmente"
        />
      </div>
    </div>

    <form v-else class="space-y-4" novalidate @submit.prevent="guardar()">
      <Message v-if="sinLectura" severity="warn" icon="pi pi-exclamation-triangle" data-sin-lectura>
        No se pudo leer la factura. Ingrese los datos; el archivo queda adjunto.
      </Message>
      <Message v-else-if="lectura" severity="info" icon="pi pi-sparkles" data-leida>
        Revise los datos leídos y corrija lo necesario. Los marcados en ámbar tienen baja confianza.
      </Message>

      <!-- CU11 pasos 1–2: datos de la factura y su archivo. -->
      <fieldset class="rounded-lg border border-surface-200 bg-white p-4">
        <legend class="px-1 font-semibold">Datos de la factura</legend>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div class="space-y-1.5 sm:col-span-2">
            <span class="block text-sm font-medium text-surface-700">Proyecto</span>
            <p class="font-medium" data-proyecto>
              {{ proyecto ? `${proyecto.codigo} · ${proyecto.nombre}` : '—' }}
            </p>
          </div>

          <CampoFormulario
            v-slot="{ idError }"
            etiqueta="N.° de factura"
            para="numero"
            :error="errores.numero"
            :aviso="aviso('numero', numero)"
            obligatorio
            ancho="medio"
          >
            <InputText
              id="numero"
              v-model="numero"
              maxlength="20"
              placeholder="Ej.: F001-00002803"
              autocapitalize="characters"
              :invalid="!!errores.numero"
              :aria-describedby="idError"
              fluid
            />
          </CampoFormulario>

          <CampoFormulario
            v-slot="{ idError }"
            etiqueta="Fecha de emisión"
            para="fecha"
            :error="errores.fecha"
            :aviso="aviso('fecha', fecha)"
            obligatorio
            ancho="medio"
          >
            <InputText
              id="fecha"
              v-model="fecha"
              type="date"
              :max="hoy()"
              :invalid="!!errores.fecha"
              :aria-describedby="idError"
              fluid
            />
          </CampoFormulario>

          <CampoFormulario
            v-slot="{ idError }"
            etiqueta="Proveedor"
            para="proveedor_id"
            :error="errores.proveedor_id"
            :aviso="aviso('proveedor', proveedorId)"
            obligatorio
          >
            <Select
              :model-value="proveedorId"
              label-id="proveedor_id"
              :options="opcionesProveedor"
              option-label="etiqueta"
              option-value="id"
              placeholder="Seleccione"
              filter
              filter-placeholder="Buscar por razón social o RUC"
              empty-filter-message="No hay proveedores que coincidan"
              :invalid="!!errores.proveedor_id"
              :pt="{ label: { 'aria-describedby': idError } }"
              fluid
              @update:model-value="cambiarProveedor"
            />
          </CampoFormulario>

          <div class="space-y-1.5 sm:col-span-2">
            <span class="block text-sm font-medium text-surface-700">Archivo de la factura *</span>
            <div class="flex flex-wrap items-center gap-3">
              <span v-if="archivo" class="text-sm text-surface-600" data-archivo-adjunto>
                <i
                  class="pi mr-1"
                  :class="archivo.type === 'application/pdf' ? 'pi-file-pdf' : 'pi-image'"
                  aria-hidden="true"
                />{{ archivo.name }} · {{ tamano(archivo.size) }}
              </span>
              <span v-else class="text-sm text-surface-500">Sin archivo</span>
              <Button
                type="button"
                label="Cambiar archivo"
                icon="pi pi-arrow-left"
                size="small"
                text
                :disabled="guardando"
                @click="paso = 'archivo'"
              />
            </div>
            <small v-if="errores.archivo" class="text-red-600">{{ errores.archivo }}</small>
          </div>
        </div>
      </fieldset>

      <!-- Detalle por resistencia: volumen facturado (CU11 paso 5) y monto. -->
      <fieldset class="space-y-3 rounded-lg border border-surface-200 bg-white p-4">
        <legend class="px-1 font-semibold">Concreto por resistencia</legend>
        <p class="text-sm text-surface-500">
          Cada concreto que cobra la factura con su volumen y precio unitario sin IGV.
        </p>

        <div
          v-for="(linea, i) in lineas"
          :key="i"
          class="grid grid-cols-2 gap-2 border-b border-surface-100 pb-3 sm:grid-cols-[1fr_8rem_9rem_8rem_auto] sm:items-start"
          data-linea
        >
          <div class="col-span-2 flex flex-col gap-1 sm:col-span-1">
            <label :for="`resistencia-${i}`" class="text-sm font-medium">Resistencia</label>
            <Select
              v-model="linea.resistencia_id"
              :label-id="`resistencia-${i}`"
              :options="resistencias"
              option-label="nombre"
              option-value="id"
              placeholder="Seleccione"
              :invalid="!!errores[`lineas.${i}.resistencia_id`]"
              fluid
            />
            <small v-if="errores[`lineas.${i}.resistencia_id`]" class="text-red-600">{{
              errores[`lineas.${i}.resistencia_id`]
            }}</small>
            <small
              v-else-if="lineasLeidas[i]?.revisar"
              class="flex items-center gap-1 text-amber-700"
              data-aviso-linea
            >
              <i class="pi pi-exclamation-triangle text-xs" aria-hidden="true" />
              {{
                lineasLeidas[i]!.texto
                  ? `Se leyó «${lineasLeidas[i]!.texto}»; verifique la línea`
                  : 'Verifique la línea'
              }}
            </small>
          </div>
          <div class="flex flex-col gap-1">
            <label :for="`volumen-${i}`" class="text-sm font-medium">Volumen (m³)</label>
            <InputNumber
              v-model="linea.volumen_m3"
              v-bind="decimal"
              :min-fraction-digits="0"
              :input-id="`volumen-${i}`"
              input-mode="decimal"
              :invalid="!!errores[`lineas.${i}.volumen_m3`]"
            />
            <small v-if="errores[`lineas.${i}.volumen_m3`]" class="text-red-600">{{
              errores[`lineas.${i}.volumen_m3`]
            }}</small>
          </div>
          <div class="flex flex-col gap-1">
            <label :for="`precio-${i}`" class="text-sm font-medium">P. unit. (S/)</label>
            <InputNumber
              v-model="linea.precio_unitario"
              v-bind="decimal"
              :input-id="`precio-${i}`"
              input-mode="decimal"
              :invalid="!!errores[`lineas.${i}.precio_unitario`]"
            />
            <small v-if="errores[`lineas.${i}.precio_unitario`]" class="text-red-600">{{
              errores[`lineas.${i}.precio_unitario`]
            }}</small>
          </div>
          <div class="flex flex-col gap-1">
            <span class="text-sm font-medium">Importe (S/)</span>
            <span class="py-2 text-right font-medium sm:text-left" data-importe>{{
              linea.volumen_m3 && linea.precio_unitario
                ? decimales(importe(linea.volumen_m3, linea.precio_unitario))
                : '—'
            }}</span>
          </div>
          <Button
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            class="sm:mt-6"
            :aria-label="`Quitar resistencia ${i + 1}`"
            :disabled="lineas.length === 1"
            @click="quitarLinea(i)"
          />
        </div>
        <p v-if="errores.lineas" class="text-red-600" role="alert">{{ errores.lineas }}</p>
        <Button
          label="Agregar resistencia"
          icon="pi pi-plus"
          outlined
          size="small"
          :disabled="lineas.length >= 10"
          @click="agregarLinea"
        />
      </fieldset>

      <!-- Lo que no es concreto: suma al costo, no al volumen. -->
      <fieldset class="space-y-3 rounded-lg border border-surface-200 bg-white p-4">
        <legend class="px-1 font-semibold">Otros cargos</legend>
        <p class="text-sm text-surface-500">
          Bombeo, aditivos, tiempo de espera u otros servicios de la factura, sin IGV. Suman al
          costo pero no al volumen de concreto.
        </p>
        <div
          v-for="(cargo, i) in cargos"
          :key="i"
          class="grid grid-cols-[1fr_9rem_auto] items-start gap-2"
          data-cargo
        >
          <div class="flex flex-col gap-1">
            <label :for="`cargo-${i}`" class="text-sm font-medium">Descripción</label>
            <InputText
              :id="`cargo-${i}`"
              v-model="cargo.descripcion"
              maxlength="200"
              placeholder="Ej.: Servicio de bombeo"
              :invalid="!!errores[`cargos.${i}.descripcion`]"
              fluid
            />
            <small v-if="errores[`cargos.${i}.descripcion`]" class="text-red-600">{{
              errores[`cargos.${i}.descripcion`]
            }}</small>
            <small v-else-if="cargo.revisar" class="flex items-center gap-1 text-amber-700">
              <i class="pi pi-exclamation-triangle text-xs" aria-hidden="true" />Verifique este
              cargo
            </small>
          </div>
          <div class="flex flex-col gap-1">
            <label :for="`cargo-importe-${i}`" class="text-sm font-medium">Importe (S/)</label>
            <InputNumber
              v-model="cargo.importe"
              v-bind="decimal"
              :input-id="`cargo-importe-${i}`"
              input-mode="decimal"
              :invalid="!!errores[`cargos.${i}.importe`]"
            />
            <small v-if="errores[`cargos.${i}.importe`]" class="text-red-600">{{
              errores[`cargos.${i}.importe`]
            }}</small>
          </div>
          <Button
            icon="pi pi-trash"
            text
            rounded
            severity="danger"
            class="mt-6"
            :aria-label="`Quitar cargo ${i + 1}`"
            @click="quitarCargo(i)"
          />
        </div>
        <Button
          label="Agregar cargo"
          icon="pi pi-plus"
          outlined
          size="small"
          :disabled="cargos.length >= 20"
          @click="agregarCargo"
        />
      </fieldset>

      <!-- Montos de la factura. -->
      <fieldset class="rounded-lg border border-surface-200 bg-white p-4">
        <legend class="px-1 font-semibold">Montos</legend>
        <dl class="ml-auto grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-2 sm:w-96">
          <dt class="text-sm text-surface-600">Volumen de concreto</dt>
          <dd class="text-right font-medium" data-volumen-facturado>
            {{ decimales(volumenFacturado) }} m³
          </dd>
          <dt class="text-sm text-surface-600">Concreto</dt>
          <dd class="text-right">{{ soles(subtotalConcreto) }}</dd>
          <template v-if="cargos.length">
            <dt class="text-sm text-surface-600">Otros cargos</dt>
            <dd class="text-right" data-subtotal-cargos>{{ soles(subtotalCargos) }}</dd>
          </template>
          <dt class="text-sm text-surface-600">Subtotal</dt>
          <dd class="text-right font-medium" data-subtotal>{{ soles(subtotal) }}</dd>
          <dt>
            <label for="igv" class="text-sm text-surface-600">IGV (S/)</label>
          </dt>
          <dd class="w-36 justify-self-end">
            <InputNumber
              :model-value="igv"
              v-bind="decimal"
              input-id="igv"
              input-mode="decimal"
              :invalid="!!errores.igv"
              @update:model-value="cambiarIgv"
            />
          </dd>
          <dd v-if="errores.igv" class="col-span-2 text-right text-sm text-red-600">
            {{ errores.igv }}
          </dd>
          <dt class="border-t border-surface-200 pt-2 font-semibold">Monto total</dt>
          <dd class="border-t border-surface-200 pt-2 text-right text-lg font-semibold" data-total>
            {{ soles(total) }}
          </dd>
          <dd
            v-if="totalDistinto"
            class="col-span-2 flex items-center justify-end gap-1 text-sm text-amber-700"
            data-total-distinto
          >
            <i class="pi pi-exclamation-triangle text-xs" aria-hidden="true" />La factura indica
            {{ soles(totalLeido!) }}; revise los importes
          </dd>
        </dl>
      </fieldset>

      <!-- CU11 paso 3: CU12 Vincular factura con guías de remisión. -->
      <fieldset class="space-y-3 rounded-lg border border-surface-200 bg-white p-4">
        <legend class="px-1 font-semibold">Guías que cobra</legend>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-surface-500">
            {{
              vinculadas.length
                ? `${vinculadas.length} ${vinculadas.length === 1 ? 'guía vinculada' : 'guías vinculadas'}: ${decimales(volumenGuias)} m³ – ${soles(importeGuias)}`
                : 'Vincule las guías de remisión del proveedor que cobra esta factura.'
            }}
          </p>
          <Button
            :label="vinculadas.length ? 'Cambiar guías' : 'Vincular guías'"
            icon="pi pi-link"
            outlined
            data-vincular
            @click="abrirVincular"
          />
        </div>

        <Message v-if="propuesta.origen" severity="info" icon="pi pi-sparkles" data-propuesta>
          {{
            propuesta.origen === 'citadas'
              ? 'Se marcaron las guías que cita la factura.'
              : 'La factura no cita guías: se propusieron las pendientes que completan el volumen, de la más antigua. Verifíquelas.'
          }}
        </Message>
        <Message
          v-if="propuesta.avisos.length"
          severity="warn"
          icon="pi pi-exclamation-triangle"
          data-avisos-guias
        >
          <ul class="list-disc pl-5 text-sm">
            <li v-for="a in propuesta.avisos" :key="a">{{ a }}</li>
          </ul>
        </Message>

        <div v-if="vinculadas.length" class="overflow-x-auto">
          <table class="w-full text-sm" aria-label="Guías vinculadas">
            <thead>
              <tr class="border-b border-surface-200 text-left text-surface-600">
                <th class="px-2 py-2 font-medium">N.° guía</th>
                <th class="px-2 py-2 font-medium">Fecha</th>
                <th class="hidden px-2 py-2 font-medium sm:table-cell">Resistencia</th>
                <th class="px-2 py-2 text-right font-medium">Volumen m³</th>
                <th class="px-2 py-2 text-right font-medium">Importe S/</th>
                <th class="w-px"><span class="sr-only">Acciones</span></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="g in vinculadas"
                :key="g.id"
                class="border-b border-surface-100"
                data-vinculada
              >
                <td class="px-2 py-1.5 font-medium">{{ g.numero }}</td>
                <td class="px-2 py-1.5">{{ formatoFecha(g.fecha) }}</td>
                <td class="hidden px-2 py-1.5 sm:table-cell">{{ g.resistencia.nombre }}</td>
                <td class="px-2 py-1.5 text-right">{{ decimales(g.volumen_m3) }}</td>
                <td class="px-2 py-1.5 text-right">
                  {{ importeDeGuia(g) !== null ? decimales(importeDeGuia(g)!) : '—' }}
                </td>
                <td class="px-1">
                  <Button
                    icon="pi pi-times"
                    text
                    rounded
                    size="small"
                    severity="secondary"
                    :aria-label="`Quitar guía ${g.numero}`"
                    @click="quitarGuia(g.id)"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- CU11 paso 5: volumen facturado frente al de las guías, por resistencia. -->
        <div v-if="vinculadas.length" class="space-y-2" data-comparacion>
          <Message v-if="coinciden" severity="success" icon="pi pi-check-circle" data-coinciden>
            El volumen facturado coincide con las guías.
          </Message>
          <Message v-else severity="warn" icon="pi pi-exclamation-triangle" data-no-coinciden>
            El volumen facturado no coincide con las guías.
            <ul class="mt-1 list-disc pl-5 text-sm">
              <li
                v-for="c in comparacion.filter((c) => c.facturado !== c.guias)"
                :key="c.resistencia_id"
              >
                {{ nombreResistencia(c.resistencia_id) }}: facturado
                {{ decimales(c.facturado) }} m³, guías {{ decimales(c.guias) }} m³
              </li>
            </ul>
          </Message>
        </div>

        <p v-if="errores.guia_ids" class="text-red-600" role="alert">{{ errores.guia_ids }}</p>
      </fieldset>

      <div class="flex flex-col gap-2 sm:flex-row-reverse">
        <Button type="submit" label="Guardar" icon="pi pi-check" :loading="guardando" />
        <Button
          type="button"
          label="Cancelar"
          severity="secondary"
          outlined
          :disabled="guardando"
          @click="router.push({ name: 'facturas' })"
        />
      </div>
    </form>

    <DialogoVincularGuias
      v-model:visible="dialogoGuias"
      :guias="pendientes"
      :seleccion="vinculadas.map((g) => g.id)"
      :precios="precios"
      :lineas="lineas"
      :numero-factura="numero.trim().toUpperCase()"
      :cargando="cargandoGuias"
      @confirmar="vincular"
    />
  </section>
</template>
