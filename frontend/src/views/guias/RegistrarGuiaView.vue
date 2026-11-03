<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Message from 'primevue/message'
import EncabezadoPagina from '@/components/organismos/EncabezadoPagina.vue'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'
import { guiasServicio } from '@/services/guias'
import { comprimirFoto } from '@/services/imagen'
import { resistenciasServicio } from '@/services/resistencias'
import { esquemaGuia, hoy, PROVEEDOR_NUEVO, type ValoresGuia } from '@/validaciones/guia'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import type { CampoLectura, LecturaGuia, ProveedorGuia, RegistroGuia } from '@/types/guias'
import type { Proveedor } from '@/types/proveedor'
import type { Resistencia } from '@/types/resistencias'

const toast = useToast()
const proyectoActual = useProyectoActualStore()
// El proyecto se elige en el encabezado (SelectorProyecto); CU06 paso 1.
const { proyecto, proyectoId } = storeToRefs(proyectoActual)

const paso = ref<'foto' | 'datos'>('foto')
const foto = ref<Blob | null>(null)
const vistaPrevia = ref<string | null>(null)
const preparandoFoto = ref(false)
/** La foto ya subida en la lectura: el registro la usa sin volver a enviarla. */
const fotoTemporal = ref<string | null>(null)
const lectura = ref<LecturaGuia | null>(null)
/** El OCR no respondió o no reconoció la guía (CU06 flujo alternativo 1). */
const sinLectura = ref(false)
const leyendo = ref(false)
const proveedores = ref<Proveedor[]>([])
const resistencias = ref<Resistencia[]>([])
const proveedorNuevo = ref<{ ruc: string; razon_social: string } | null>(null)

const opcionesProveedor = computed(() => [
  ...(proveedorNuevo.value
    ? [
        {
          id: PROVEEDOR_NUEVO,
          etiqueta: `${proveedorNuevo.value.razon_social} · RUC ${proveedorNuevo.value.ruc} (nuevo)`,
        },
      ]
    : []),
  ...proveedores.value.map((p) => ({ id: p.id, etiqueta: `${p.razon_social} · RUC ${p.ruc}` })),
])

function avisarError(error: unknown) {
  toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
}

onMounted(async () => {
  try {
    await Promise.all([
      proyectoActual.cargar(),
      guiasServicio.proveedores().then((p) => (proveedores.value = p)),
      resistenciasServicio.listar().then((r) => (resistencias.value = r)),
    ])
  } catch (error) {
    avisarError(error)
  }
})

function mostrarFoto(archivo: Blob | null) {
  if (vistaPrevia.value) URL.revokeObjectURL(vistaPrevia.value)
  foto.value = archivo
  vistaPrevia.value = archivo ? URL.createObjectURL(archivo) : null
}
onBeforeUnmount(() => mostrarFoto(null))

async function elegirFoto(evento: Event) {
  const entrada = evento.target as HTMLInputElement
  const archivo = entrada.files?.[0]
  entrada.value = ''
  if (!archivo) return
  preparandoFoto.value = true
  try {
    mostrarFoto(await comprimirFoto(archivo))
    fotoTemporal.value = null
  } finally {
    preparandoFoto.value = false
  }
}

const form = useForm({ validationSchema: toTypedSchema(esquemaGuia) })
const [numero, numeroAttrs] = form.defineField('numero')
const [fecha, fechaAttrs] = form.defineField('fecha')
const [proveedorId, proveedorIdAttrs] = form.defineField('proveedor_id')
const [resistenciaId, resistenciaIdAttrs] = form.defineField('resistencia_id')
const [volumen, volumenAttrs] = form.defineField('volumen_m3')
const [placa, placaAttrs] = form.defineField('placa')
const [codigoProducto, codigoProductoAttrs] = form.defineField('codigo_producto')

const texto = (l: LecturaGuia, campo: CampoLectura) => {
  const valor = l.campos[campo].valor
  return valor === null ? undefined : String(valor)
}

/** CU06 paso 4: los datos leídos quedan en el formulario para revisarlos y corregirlos. */
function llenar(l: LecturaGuia) {
  const { ruc, proveedor, volumen_m3 } = l.campos
  // Un RUC nuevo se registra con la guía solo si se leyó con seguridad, con su razón social.
  proveedorNuevo.value =
    !l.proveedor_id && ruc.valor && !ruc.revisar && proveedor.valor
      ? { ruc: String(ruc.valor), razon_social: String(proveedor.valor) }
      : null
  form.resetForm({
    values: {
      numero: texto(l, 'numero'),
      fecha: texto(l, 'fecha'),
      proveedor_id: l.proveedor_id ?? (proveedorNuevo.value ? PROVEEDOR_NUEVO : undefined),
      resistencia_id: l.resistencia_id ?? undefined,
      volumen_m3: typeof volumen_m3.valor === 'number' ? volumen_m3.valor : undefined,
      placa: texto(l, 'placa'),
      codigo_producto: texto(l, 'codigo_producto'),
    },
  })
}

function formularioVacio() {
  proveedorNuevo.value = null
  form.resetForm({ values: { fecha: hoy() } })
}

/** CU06 pasos 2–4 (CU07). Si el OCR no responde, sigue con el registro manual (CU08). */
async function leer() {
  if (!foto.value || !proyectoId.value) return
  leyendo.value = true
  try {
    const respuesta = await guiasServicio.leer(proyectoId.value, foto.value)
    fotoTemporal.value = respuesta.foto_temporal
    lectura.value = respuesta.lectura
    sinLectura.value = !respuesta.lectura
    if (respuesta.lectura) llenar(respuesta.lectura)
    else formularioVacio()
    paso.value = 'datos'
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (campos.foto) {
      toast.add({ severity: 'error', summary: campos.foto, life: 5000 })
      return
    }
    // Sin respuesta del servidor o del OCR: también se registra a mano, con la foto adjunta.
    lectura.value = null
    sinLectura.value = true
    formularioVacio()
    paso.value = 'datos'
  } finally {
    leyendo.value = false
  }
}

/** CU08: el almacenero elige «Registrar manualmente». */
function registrarManualmente() {
  lectura.value = null
  sinLectura.value = false
  formularioVacio()
  paso.value = 'datos'
}

function volverAFoto() {
  paso.value = 'foto'
}

const camposLeidos: Record<keyof ValoresGuia, CampoLectura[]> = {
  numero: ['numero'],
  fecha: ['fecha'],
  proveedor_id: ['proveedor', 'ruc'],
  resistencia_id: ['resistencia'],
  volumen_m3: ['volumen_m3'],
  placa: ['placa'],
  codigo_producto: ['codigo_producto'],
}

/** CU07 paso 4: los campos de baja confianza o no identificados, hasta que se corrigen. */
function aviso(campo: keyof ValoresGuia) {
  const l = lectura.value
  if (!l || form.isFieldDirty(campo)) return undefined
  const leidos = camposLeidos[campo].map((c) => l.campos[c])
  if (campo === 'proveedor_id' && form.values.proveedor_id === undefined) {
    const { proveedor, ruc } = l.campos
    return proveedor.valor || ruc.valor
      ? `Se leyó «${[proveedor.valor, ruc.valor && `RUC ${ruc.valor}`].filter(Boolean).join(' · ')}»; elija el proveedor`
      : 'No se pudo leer; elija el proveedor'
  }
  if (campo === 'resistencia_id' && form.values.resistencia_id === undefined) {
    const { descripcion_producto, codigo_producto } = l.campos
    const leido = descripcion_producto.valor ?? codigo_producto.valor
    return leido
      ? `Se leyó «${leido}»; elija la resistencia`
      : 'No se pudo leer; elija la resistencia'
  }
  if (leidos.every((c) => c.valor === null)) {
    return campo === 'placa' || campo === 'codigo_producto'
      ? undefined
      : 'No se pudo leer; ingrese este dato'
  }
  return leidos.some((c) => c.revisar) ? 'Verifique este dato' : undefined
}

function proveedorDeGuia(id: number): ProveedorGuia {
  return id === PROVEEDOR_NUEVO && proveedorNuevo.value
    ? {
        proveedor_ruc: proveedorNuevo.value.ruc,
        proveedor_razon_social: proveedorNuevo.value.razon_social,
      }
    : { proveedor_id: id }
}

async function enviar(valores: ValoresGuia) {
  const { proveedor_id, ...datos } = valores
  // La descripción leída acompaña al código leído, para registrar el producto si es nuevo.
  const descripcion = lectura.value?.campos.descripcion_producto.valor
  const registro: RegistroGuia = {
    ...datos,
    ...(datos.codigo_producto && descripcion ? { descripcion_producto: String(descripcion) } : {}),
    proyecto_id: proyectoId.value!,
    ...proveedorDeGuia(proveedor_id),
    ...(fotoTemporal.value ? { foto_temporal: fotoTemporal.value } : { foto: foto.value! }),
  }
  try {
    await guiasServicio.registrar(registro)
  } catch (error) {
    const campos = erroresDeValidacion(error)
    // La foto subida en la lectura ya no está (se limpia al día siguiente): se envía de nuevo.
    if ((campos.foto || campos.foto_temporal) && fotoTemporal.value && foto.value) {
      fotoTemporal.value = null
      return enviar(valores)
    }
    if (Object.keys(campos).length) form.setErrors(campos)
    else avisarError(error)
    return
  }

  toast.add({ severity: 'success', summary: 'Guía registrada correctamente', life: 4000 })
  mostrarFoto(null)
  fotoTemporal.value = null
  lectura.value = null
  sinLectura.value = false
  proveedorNuevo.value = null
  paso.value = 'foto'
}

const guardar = form.handleSubmit(enviar)

const decimal = { locale: 'es-PE', minFractionDigits: 0, maxFractionDigits: 2, min: 0, fluid: true }
</script>

<template>
  <section class="mx-auto max-w-2xl space-y-4">
    <EncabezadoPagina
      titulo="Registrar guía"
      :subtitulo="
        proyecto ? `Concreto recibido en ${proyecto.nombre}` : 'Concreto recibido en obra'
      "
    />

    <Message
      v-if="proyectoActual.cargado && !proyecto"
      severity="warn"
      icon="pi pi-exclamation-triangle"
    >
      No tiene proyectos activos asignados. Pida al administrador que lo asigne a uno.
    </Message>

    <!-- CU06 pasos 1–2: tomar la foto de la guía. -->
    <div
      v-else-if="paso === 'foto'"
      class="space-y-4 rounded-lg border border-surface-200 bg-white p-4"
    >
      <label
        for="foto-guia"
        class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-surface-300 bg-surface-50 text-center text-surface-600 hover:border-primary hover:text-primary-700"
        :class="vistaPrevia ? 'p-2' : 'min-h-64 p-6'"
      >
        <img
          v-if="vistaPrevia"
          :src="vistaPrevia"
          alt="Foto de la guía de remisión"
          class="max-h-[28rem] w-full rounded object-contain"
        />
        <template v-else>
          <i
            class="pi text-4xl"
            :class="preparandoFoto ? 'pi-spin pi-spinner' : 'pi-camera'"
            aria-hidden="true"
          />
          <span class="text-base font-medium">Tomar foto de la guía</span>
          <span class="text-sm text-surface-500"
            >Encuadre la guía completa, de frente y con buena luz.</span
          >
        </template>
      </label>
      <input
        id="foto-guia"
        type="file"
        accept="image/*"
        capture="environment"
        class="sr-only"
        @change="elegirFoto"
      />

      <div class="flex flex-col gap-2 sm:flex-row-reverse">
        <Button
          :label="leyendo ? 'Leyendo guía…' : 'Leer guía'"
          icon="pi pi-sparkles"
          class="sm:flex-1"
          :loading="leyendo"
          :disabled="!foto || !proyecto || preparandoFoto"
          @click="leer"
        />
        <Button
          v-if="vistaPrevia"
          as="label"
          for="foto-guia"
          label="Cambiar foto"
          icon="pi pi-refresh"
          severity="secondary"
          outlined
          :disabled="leyendo"
        />
      </div>
      <p class="text-center text-sm">
        <button
          type="button"
          class="text-primary-700 underline-offset-2 hover:underline disabled:text-surface-400"
          :disabled="!foto || !proyecto || leyendo"
          data-registrar-manual
          @click="registrarManualmente"
        >
          Registrar manualmente
        </button>
      </p>
    </div>

    <!-- CU06 pasos 4–5 / CU08: revisar o digitar los datos y guardar. -->
    <form
      v-else
      class="space-y-4 rounded-lg border border-surface-200 bg-white p-4"
      novalidate
      @submit.prevent="guardar"
    >
      <div class="flex gap-3">
        <img
          v-if="vistaPrevia"
          :src="vistaPrevia"
          alt="Foto de la guía de remisión"
          class="size-20 shrink-0 rounded border border-surface-200 object-cover"
        />
        <Message
          v-if="sinLectura"
          severity="warn"
          icon="pi pi-exclamation-triangle"
          class="flex-1"
          data-sin-lectura
        >
          No se pudo leer la guía. Ingrese los datos; la foto queda adjunta.
        </Message>
        <Message v-else-if="lectura" severity="info" icon="pi pi-sparkles" class="flex-1">
          Revise los datos leídos y corrija lo necesario. Los marcados en ámbar tienen baja
          confianza.
        </Message>
        <Message v-else severity="secondary" icon="pi pi-pencil" class="flex-1">
          Ingrese los datos de la guía; la foto queda adjunta.
        </Message>
      </div>

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Número de guía"
          para="numero"
          :error="form.errors.value.numero"
          :aviso="aviso('numero')"
          obligatorio
          ancho="medio"
        >
          <InputText
            id="numero"
            v-model="numero"
            v-bind="numeroAttrs"
            maxlength="20"
            placeholder="Ej.: T001-00012345"
            autocapitalize="characters"
            :invalid="!!form.errors.value.numero"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Fecha"
          para="fecha"
          :error="form.errors.value.fecha"
          :aviso="aviso('fecha')"
          obligatorio
          ancho="medio"
        >
          <!-- El selector de fecha nativo es el más cómodo en el celular. -->
          <InputText
            id="fecha"
            v-model="fecha"
            v-bind="fechaAttrs"
            type="date"
            :max="hoy()"
            :invalid="!!form.errors.value.fecha"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Proveedor"
          para="proveedor_id"
          :error="form.errors.value.proveedor_id"
          :aviso="aviso('proveedor_id')"
          obligatorio
        >
          <!-- Select no pasa los atributos al elemento que recibe el foco. -->
          <Select
            v-model="proveedorId"
            v-bind="proveedorIdAttrs"
            label-id="proveedor_id"
            :options="opcionesProveedor"
            option-label="etiqueta"
            option-value="id"
            placeholder="Seleccione"
            filter
            filter-placeholder="Buscar por razón social o RUC"
            empty-filter-message="No hay proveedores que coincidan"
            :invalid="!!form.errors.value.proveedor_id"
            :pt="{ label: { 'aria-describedby': idError } }"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Resistencia"
          para="resistencia_id"
          :error="form.errors.value.resistencia_id"
          :aviso="aviso('resistencia_id')"
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
            :invalid="!!form.errors.value.resistencia_id"
            :pt="{ label: { 'aria-describedby': idError } }"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Código del producto"
          para="codigo_producto"
          :error="form.errors.value.codigo_producto"
          :aviso="aviso('codigo_producto')"
          ancho="medio"
        >
          <InputText
            id="codigo_producto"
            v-model="codigoProducto"
            v-bind="codigoProductoAttrs"
            maxlength="30"
            placeholder="Ej.: 70280NB5746"
            autocapitalize="characters"
            :invalid="!!form.errors.value.codigo_producto"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Volumen (m³)"
          para="volumen_m3"
          :error="form.errors.value.volumen_m3"
          :aviso="aviso('volumen_m3')"
          obligatorio
          ancho="medio"
        >
          <InputNumber
            v-model="volumen"
            v-bind="{ ...volumenAttrs, ...decimal }"
            input-id="volumen_m3"
            input-mode="decimal"
            placeholder="Ej.: 8.50"
            :invalid="!!form.errors.value.volumen_m3"
            :pt="{ pcInputText: { root: { 'aria-describedby': idError } } }"
          />
        </CampoFormulario>

        <CampoFormulario
          v-slot="{ idError }"
          etiqueta="Placa del mixer"
          para="placa"
          :error="form.errors.value.placa"
          :aviso="aviso('placa')"
          ancho="medio"
        >
          <InputText
            id="placa"
            v-model="placa"
            v-bind="placaAttrs"
            maxlength="10"
            placeholder="Ej.: ABC-123"
            autocapitalize="characters"
            :invalid="!!form.errors.value.placa"
            :aria-describedby="idError"
            fluid
          />
        </CampoFormulario>
      </div>

      <div class="flex flex-col gap-2 sm:flex-row-reverse">
        <Button
          type="submit"
          label="Guardar"
          icon="pi pi-check"
          class="sm:flex-1"
          :loading="form.isSubmitting.value"
        />
        <Button
          type="button"
          label="Volver a la foto"
          icon="pi pi-arrow-left"
          severity="secondary"
          outlined
          :disabled="form.isSubmitting.value"
          @click="volverAFoto"
        />
      </div>
    </form>
  </section>
</template>
