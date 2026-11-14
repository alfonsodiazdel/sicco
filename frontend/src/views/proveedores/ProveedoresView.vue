<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useForm } from 'vee-validate'
import { toTypedSchema } from '@vee-validate/zod'
import { z } from 'zod'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import DataTable, { type DataTablePageEvent } from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Tag from 'primevue/tag'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import { proveedoresApi } from '@/api/proveedores'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { CONDICIONES_PAGO, type Proveedor } from '@/types/proveedor'

const auth = useAuthStore()
const toast = useToast()
const confirm = useConfirm()

const puedeGestionar = computed(() => auth.tieneRol('oficina_tecnica'))
const puedeVerContacto = computed(() => auth.tieneRol('oficina_tecnica', 'administrador'))

// Listado
const proveedores = ref<Proveedor[]>([])
const total = ref(0)
const porPagina = ref(15)
const pagina = ref(1)
const buscar = ref('')
const cargando = ref(false)

async function cargar() {
  cargando.value = true
  try {
    const res = await proveedoresApi.listar({
      buscar: buscar.value.trim() || undefined,
      page: pagina.value,
      per_page: porPagina.value,
    })
    proveedores.value = res.data
    total.value = res.meta.total
  } catch (error) {
    toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  } finally {
    cargando.value = false
  }
}

function cambiarPagina(evento: DataTablePageEvent) {
  pagina.value = evento.page + 1
  porPagina.value = evento.rows
  cargar()
}

let temporizador: ReturnType<typeof setTimeout> | undefined
watch(buscar, () => {
  clearTimeout(temporizador)
  temporizador = setTimeout(() => {
    pagina.value = 1
    cargar()
  }, 350)
})

onMounted(cargar)

// Formulario (registrar / editar)
const requerido = 'Este dato es requerido'
const dialogoVisible = ref(false)
const editando = ref<Proveedor | null>(null)

const { defineField, handleSubmit, errors, setErrors, resetForm, isSubmitting } = useForm({
  validationSchema: toTypedSchema(
    z.object({
      ruc: z
        .string({ required_error: requerido })
        .trim()
        .min(1, requerido)
        .regex(/^\d{11}$/, 'El RUC debe tener 11 dígitos')
        .regex(/^(10|15|16|17|20)/, 'El RUC debe empezar con 10, 15, 16, 17 o 20'),
      razon_social: z
        .string({ required_error: requerido })
        .trim()
        .min(1, requerido)
        .max(200, 'Máximo 200 caracteres'),
      contacto_nombre: z.string().trim().max(150, 'Máximo 150 caracteres').optional(),
      contacto_telefono: z
        .string()
        .trim()
        .regex(/^(9\d{8})?$/, 'El teléfono debe tener 9 dígitos y empezar con 9')
        .optional(),
      contacto_correo: z
        .string()
        .trim()
        .email('Ingrese un correo válido')
        .or(z.literal(''))
        .optional(),
      correo_facturacion: z
        .string()
        .trim()
        .email('Ingrese un correo válido')
        .or(z.literal(''))
        .optional(),
      condicion_pago: z.enum(['contado', '30', '60', '90']).nullish(),
      direccion_fiscal: z.string().trim().max(250, 'Máximo 250 caracteres').optional(),
      planta_despacho: z.string().trim().max(150, 'Máximo 150 caracteres').optional(),
      activo: z.boolean().optional(),
    }),
  ),
})

const [ruc, rucAttrs] = defineField('ruc')
const [razonSocial, razonSocialAttrs] = defineField('razon_social')
const [contactoNombre, contactoNombreAttrs] = defineField('contacto_nombre')
const [contactoTelefono, contactoTelefonoAttrs] = defineField('contacto_telefono')
const [contactoCorreo, contactoCorreoAttrs] = defineField('contacto_correo')
const [correoFacturacion, correoFacturacionAttrs] = defineField('correo_facturacion')
const [condicionPago, condicionPagoAttrs] = defineField('condicion_pago')
const [direccionFiscal, direccionFiscalAttrs] = defineField('direccion_fiscal')
const [plantaDespacho, plantaDespachoAttrs] = defineField('planta_despacho')
const [activo] = defineField('activo')

const valoresVacios = {
  ruc: '',
  razon_social: '',
  contacto_nombre: '',
  contacto_telefono: '',
  contacto_correo: '',
  correo_facturacion: '',
  condicion_pago: null,
  direccion_fiscal: '',
  planta_despacho: '',
  activo: true,
}

/** Los campos vacíos viajan como null: el servidor los guarda como "sin dato". */
function sinVacios<T extends Record<string, unknown>>(valores: T) {
  return Object.fromEntries(Object.entries(valores).map(([k, v]) => [k, v === '' ? null : v])) as T
}

function abrirNuevo() {
  editando.value = null
  resetForm({ values: { ...valoresVacios } })
  dialogoVisible.value = true
}

function abrirEdicion(proveedor: Proveedor) {
  editando.value = proveedor
  resetForm({
    values: {
      ruc: proveedor.ruc,
      razon_social: proveedor.razon_social,
      contacto_nombre: proveedor.contacto_nombre ?? '',
      contacto_telefono: proveedor.contacto_telefono ?? '',
      contacto_correo: proveedor.contacto_correo ?? '',
      correo_facturacion: proveedor.correo_facturacion ?? '',
      condicion_pago: proveedor.condicion_pago ?? null,
      direccion_fiscal: proveedor.direccion_fiscal ?? '',
      planta_despacho: proveedor.planta_despacho ?? '',
      activo: proveedor.activo ?? true,
    },
  })
  dialogoVisible.value = true
}

const guardar = handleSubmit(async (formulario) => {
  const valores = sinVacios(formulario)
  try {
    if (editando.value) {
      await proveedoresApi.actualizar(editando.value.id, valores)
      toast.add({ severity: 'success', summary: 'Proveedor actualizado', life: 3000 })
    } else {
      await proveedoresApi.crear(valores)
      toast.add({ severity: 'success', summary: 'Proveedor registrado', life: 3000 })
    }
    dialogoVisible.value = false
    await cargar()
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) setErrors(campos)
    else toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
  }
})

function confirmarEliminacion(proveedor: Proveedor) {
  confirm.require({
    header: 'Eliminar proveedor',
    message: `¿Eliminar a ${proveedor.razon_social} (RUC ${proveedor.ruc})?`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancelar', severity: 'secondary', text: true },
    acceptProps: { label: 'Eliminar', severity: 'danger' },
    accept: async () => {
      try {
        await proveedoresApi.eliminar(proveedor.id)
        toast.add({ severity: 'success', summary: 'Proveedor eliminado', life: 3000 })
        if (proveedores.value.length === 1 && pagina.value > 1) pagina.value--
        await cargar()
      } catch (error) {
        toast.add({ severity: 'error', summary: mensajeDeError(error), life: 5000 })
      }
    },
  })
}
</script>

<template>
  <section class="mx-auto max-w-5xl">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h2 class="text-2xl font-semibold tracking-tight text-surface-900">Proveedores</h2>
        <p class="mt-1 text-surface-500">Concreteras que emiten las guías y facturas.</p>
      </div>
      <Button v-if="puedeGestionar" label="Nuevo proveedor" icon="pi pi-plus" @click="abrirNuevo" />
    </div>

    <div class="mt-6 rounded-xl border border-surface-200 bg-white">
      <div class="border-b border-surface-200 p-4">
        <IconField>
          <InputIcon class="pi pi-search" />
          <InputText
            v-model="buscar"
            placeholder="Buscar por RUC o razón social"
            aria-label="Buscar proveedor"
            class="w-full sm:w-80"
          />
        </IconField>
      </div>

      <DataTable
        :value="proveedores"
        :loading="cargando"
        :total-records="total"
        :rows="porPagina"
        :first="(pagina - 1) * porPagina"
        :rows-per-page-options="[15, 30, 50]"
        data-key="id"
        lazy
        paginator
        @page="cambiarPagina"
      >
        <template #empty>
          <p class="py-6 text-center text-surface-500">
            {{
              buscar.trim()
                ? 'Ningún proveedor coincide con la búsqueda.'
                : 'No hay proveedores registrados.'
            }}
          </p>
        </template>
        <Column field="ruc" header="RUC" class="w-40 font-mono" />
        <Column field="razon_social" header="Razón social" />
        <Column v-if="puedeVerContacto" header="Contacto">
          <template #body="{ data }">{{ data.contacto_nombre || '—' }}</template>
        </Column>
        <Column v-if="puedeVerContacto" header="Teléfono" class="w-32">
          <template #body="{ data }">{{ data.contacto_telefono || '—' }}</template>
        </Column>
        <Column header="Estado" class="w-28">
          <template #body="{ data }">
            <Tag
              :value="data.activo === false ? 'Inactivo' : 'Activo'"
              :severity="data.activo === false ? 'secondary' : 'success'"
            />
          </template>
        </Column>
        <Column v-if="puedeGestionar" header="Acciones" class="w-28 text-right">
          <template #body="{ data }">
            <div class="flex justify-end gap-1">
              <Button
                icon="pi pi-pencil"
                severity="secondary"
                text
                rounded
                :aria-label="`Editar ${data.razon_social}`"
                @click="abrirEdicion(data)"
              />
              <Button
                icon="pi pi-trash"
                severity="danger"
                text
                rounded
                :aria-label="`Eliminar ${data.razon_social}`"
                @click="confirmarEliminacion(data)"
              />
            </div>
          </template>
        </Column>
      </DataTable>
    </div>

    <Dialog
      v-model:visible="dialogoVisible"
      :header="editando ? 'Editar proveedor' : 'Nuevo proveedor'"
      modal
      class="w-full max-w-xl"
    >
      <form id="form-proveedor" class="space-y-5" novalidate @submit="guardar">
        <div class="space-y-1.5">
          <label for="ruc" class="block text-sm font-medium text-surface-700">RUC</label>
          <InputText
            id="ruc"
            v-model="ruc"
            v-bind="rucAttrs"
            inputmode="numeric"
            maxlength="11"
            autocomplete="off"
            :invalid="!!errors.ruc"
            fluid
          />
          <small v-if="errors.ruc" class="text-red-600">{{ errors.ruc }}</small>
        </div>

        <div class="space-y-1.5">
          <label for="razon_social" class="block text-sm font-medium text-surface-700"
            >Razón social</label
          >
          <InputText
            id="razon_social"
            v-model="razonSocial"
            v-bind="razonSocialAttrs"
            maxlength="200"
            autocomplete="off"
            :invalid="!!errors.razon_social"
            fluid
          />
          <small v-if="errors.razon_social" class="text-red-600">{{ errors.razon_social }}</small>
        </div>

        <fieldset class="space-y-4">
          <legend class="text-sm font-semibold text-surface-900">Contacto</legend>
          <div class="space-y-1.5">
            <label for="contacto_nombre" class="block text-sm font-medium text-surface-700"
              >Nombre del contacto</label
            >
            <InputText
              id="contacto_nombre"
              v-model="contactoNombre"
              v-bind="contactoNombreAttrs"
              maxlength="150"
              :invalid="!!errors.contacto_nombre"
              fluid
            />
            <small v-if="errors.contacto_nombre" class="text-red-600">{{
              errors.contacto_nombre
            }}</small>
          </div>
          <div class="space-y-1.5">
            <label for="contacto_telefono" class="block text-sm font-medium text-surface-700"
              >Teléfono</label
            >
            <InputText
              id="contacto_telefono"
              v-model="contactoTelefono"
              v-bind="contactoTelefonoAttrs"
              inputmode="numeric"
              maxlength="9"
              :invalid="!!errors.contacto_telefono"
              fluid
            />
            <small v-if="errors.contacto_telefono" class="text-red-600">{{
              errors.contacto_telefono
            }}</small>
          </div>
          <div class="space-y-1.5">
            <label for="contacto_correo" class="block text-sm font-medium text-surface-700"
              >Correo del contacto</label
            >
            <InputText
              id="contacto_correo"
              v-model="contactoCorreo"
              v-bind="contactoCorreoAttrs"
              type="email"
              :invalid="!!errors.contacto_correo"
              fluid
            />
            <small v-if="errors.contacto_correo" class="text-red-600">{{
              errors.contacto_correo
            }}</small>
          </div>
        </fieldset>

        <fieldset class="space-y-4">
          <legend class="text-sm font-semibold text-surface-900">Condiciones comerciales</legend>
          <div class="space-y-1.5">
            <label for="correo_facturacion" class="block text-sm font-medium text-surface-700"
              >Correo de facturación</label
            >
            <InputText
              id="correo_facturacion"
              v-model="correoFacturacion"
              v-bind="correoFacturacionAttrs"
              type="email"
              :invalid="!!errors.correo_facturacion"
              fluid
            />
            <small v-if="errors.correo_facturacion" class="text-red-600">{{
              errors.correo_facturacion
            }}</small>
          </div>
          <div class="space-y-1.5">
            <label for="condicion_pago" class="block text-sm font-medium text-surface-700"
              >Condición de pago</label
            >
            <Select
              input-id="condicion_pago"
              v-model="condicionPago"
              v-bind="condicionPagoAttrs"
              :options="CONDICIONES_PAGO"
              option-label="label"
              option-value="value"
              show-clear
              placeholder="Sin definir"
              :invalid="!!errors.condicion_pago"
              fluid
            />
            <small v-if="errors.condicion_pago" class="text-red-600">{{
              errors.condicion_pago
            }}</small>
          </div>
          <div class="space-y-1.5">
            <label for="direccion_fiscal" class="block text-sm font-medium text-surface-700"
              >Dirección fiscal</label
            >
            <InputText
              id="direccion_fiscal"
              v-model="direccionFiscal"
              v-bind="direccionFiscalAttrs"
              maxlength="250"
              :invalid="!!errors.direccion_fiscal"
              fluid
            />
            <small v-if="errors.direccion_fiscal" class="text-red-600">{{
              errors.direccion_fiscal
            }}</small>
          </div>
          <div class="space-y-1.5">
            <label for="planta_despacho" class="block text-sm font-medium text-surface-700"
              >Planta de despacho</label
            >
            <InputText
              id="planta_despacho"
              v-model="plantaDespacho"
              v-bind="plantaDespachoAttrs"
              maxlength="150"
              :invalid="!!errors.planta_despacho"
              fluid
            />
            <small v-if="errors.planta_despacho" class="text-red-600">{{
              errors.planta_despacho
            }}</small>
          </div>
          <div class="flex items-center gap-3">
            <ToggleSwitch v-model="activo" input-id="activo" />
            <label for="activo" class="text-sm font-medium text-surface-700"
              >Proveedor activo</label
            >
          </div>
        </fieldset>
      </form>

      <template #footer>
        <Button label="Cancelar" severity="secondary" text @click="dialogoVisible = false" />
        <Button type="submit" form="form-proveedor" label="Guardar" :loading="isSubmitting" />
      </template>
    </Dialog>
  </section>
</template>
