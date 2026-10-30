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
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import { proveedoresApi } from '@/api/proveedores'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import type { Proveedor } from '@/types/proveedor'

const auth = useAuthStore()
const toast = useToast()
const confirm = useConfirm()

const puedeGestionar = computed(() => auth.tieneRol('oficina_tecnica'))

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
    }),
  ),
})

const [ruc, rucAttrs] = defineField('ruc')
const [razonSocial, razonSocialAttrs] = defineField('razon_social')

function abrirNuevo() {
  editando.value = null
  resetForm({ values: { ruc: '', razon_social: '' } })
  dialogoVisible.value = true
}

function abrirEdicion(proveedor: Proveedor) {
  editando.value = proveedor
  resetForm({ values: { ruc: proveedor.ruc, razon_social: proveedor.razon_social } })
  dialogoVisible.value = true
}

const guardar = handleSubmit(async (valores) => {
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
      class="w-full max-w-md"
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
      </form>

      <template #footer>
        <Button label="Cancelar" severity="secondary" text @click="dialogoVisible = false" />
        <Button type="submit" form="form-proveedor" label="Guardar" :loading="isSubmitting" />
      </template>
    </Dialog>
  </section>
</template>
