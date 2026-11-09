import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError, type AxiosResponse } from 'axios'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import type { ProyectosServicio } from '@/services/proyectos'
import type { DocumentosServicio } from '@/services/documentos'
import ExportarDocumentosView from '@/views/documentos/ExportarDocumentosView.vue'
import { useAuthStore } from '@/stores/auth'
import type { FacturaDocumento, GuiaDocumento } from '@/types/documentos'
import type { Usuario } from '@/types/auth'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: object) => void>() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    documentos: null as DocumentosServicio | null,
  },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))

vi.mock('@/services/proyectos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/proyectos')>()
  return {
    ...real,
    get proyectosServicio() {
      return servicios.proyectos
    },
  }
})

vi.mock('@/services/documentos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/documentos')>()
  return {
    ...real,
    get documentosServicio() {
      return servicios.documentos
    },
  }
})

vi.mock('@/services/facturas', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/facturas')>()
  return {
    ...real,
    facturasServicio: {
      proveedores: async () => [
        { id: 1, ruc: '20100030595', razon_social: 'UNICON S.A.' },
        { id: 2, ruc: '20512345678', razon_social: 'Concremax S.A.' },
      ],
    },
  }
})

const unicon = { id: 1, ruc: '20100030595', razon_social: 'UNICON S.A.' }
const guia = (id: number, numero: string, fecha: string): GuiaDocumento => ({
  id,
  numero,
  fecha,
  proveedor: unicon,
  volumen_m3: 8,
  estado_asignacion: 'sin_sector',
  tiene_foto: true,
})
const guias = [
  guia(1, 'T001-00000101', '2026-11-03'),
  guia(2, 'T001-00000102', '2026-11-02'),
  guia(3, 'T001-00000103', '2026-11-01'),
]
const facturas: FacturaDocumento[] = [
  {
    id: 10,
    numero: 'F001-00002803',
    fecha: '2026-11-04',
    proveedor: unicon,
    volumen_m3: 23,
    total: 9558.2,
    tiene_archivo: true,
  },
]

function crearServicio(filas = guias): DocumentosServicio {
  return {
    listarGuias: vi.fn<DocumentosServicio['listarGuias']>(async () => filas),
    listarFacturas: vi.fn<DocumentosServicio['listarFacturas']>(async () => facturas),
    descargarZip: vi.fn<DocumentosServicio['descargarZip']>(async () => ({
      blob: new Blob(['PK']),
      nombre: 'guias-PRY-001-seleccion.zip',
    })),
  }
}

async function montar(filas = guias) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  servicios.proyectos = crearProyectosEnMemoria(async () => [1, 2])
  servicios.documentos = crearServicio(filas)
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: 'oficina_tecnica' } } as Usuario
  const vista = mount(ExportarDocumentosView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

const boton = (vista: VueWrapper, texto: string) =>
  vista.findAll('button').find((b) => b.text().includes(texto))!
const numeros = (vista: VueWrapper) =>
  vista.findAll('tbody tr').map((tr) => tr.find('td span.font-medium').text())
const select = (vista: VueWrapper, id: string) =>
  vista.findAllComponents({ name: 'Select' }).find((s) => s.props('labelId') === id)

let clic: ReturnType<typeof vi.spyOn>
enableAutoUnmount(afterEach)
beforeEach(() => {
  usarRelojFalso()
  toast.add.mockClear()
  vi.stubGlobal(
    'matchMedia',
    vi.fn<() => object>(() => ({
      matches: false,
      addEventListener: vi.fn<() => void>(),
      removeEventListener: vi.fn<() => void>(),
    })),
  )
  URL.createObjectURL = vi.fn<() => string>(() => 'blob:zip')
  URL.revokeObjectURL = vi.fn<() => void>()
  clic = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})
afterEach(() => {
  vi.useRealTimers()
  clic.mockRestore()
})

describe('ExportarDocumentosView (CU18)', () => {
  it('al entrar lista todas las guías del proyecto', async () => {
    const vista = await montar()

    expect(servicios.documentos!.listarGuias).toHaveBeenCalledWith(1, {
      desde: null,
      hasta: null,
      proveedor_id: null,
      estado: null,
    })
    expect(numeros(vista)).toEqual(['T001-00000101', 'T001-00000102', 'T001-00000103'])
  })

  it('el filtro de estado solo aparece en guías; en facturas lista las facturas', async () => {
    const vista = await montar()
    expect(select(vista, 'estado')).toBeDefined()

    await vista.findComponent({ name: 'SelectButton' }).vm.$emit('update:modelValue', 'facturas')
    await asentar()

    expect(select(vista, 'estado')).toBeUndefined()
    expect(servicios.documentos!.listarFacturas).toHaveBeenCalledWith(1, {
      desde: null,
      hasta: null,
      proveedor_id: null,
    })
    expect(numeros(vista)).toEqual(['F001-00002803'])
  })

  it('«Descargar seleccionados» manda los ids de las filas marcadas', async () => {
    const vista = await montar()
    expect(boton(vista, 'Descargar seleccionados').attributes('disabled')).toBeDefined()

    const casillas = vista.findAll('tbody input[type="checkbox"]')
    await casillas[0]!.trigger('change')
    await casillas[2]!.trigger('change')
    await boton(vista, 'Descargar seleccionados').trigger('click')
    await asentar()

    expect(servicios.documentos!.descargarZip).toHaveBeenCalledWith(1, 'guias', { ids: [1, 3] })
    expect(clic).toHaveBeenCalledTimes(1)
    expect((clic.mock.contexts[0] as HTMLAnchorElement).download).toBe(
      'guias-PRY-001-seleccion.zip',
    )
  })

  it('«Descargar todo lo filtrado» manda los filtros buscados', async () => {
    const vista = await montar()
    const [desde] = vista.findAllComponents({ name: 'DatePicker' })
    await desde!.vm.$emit('update:modelValue', new Date(2026, 10, 1))
    await select(vista, 'proveedor')!.vm.$emit('update:modelValue', 2)
    await select(vista, 'estado')!.vm.$emit('update:modelValue', 'asignada')
    await vista.get('form').trigger('submit')
    await asentar()

    await boton(vista, 'Descargar todo lo filtrado').trigger('click')
    await asentar()

    const filtro = { desde: '2026-11-01', hasta: null, proveedor_id: 2, estado: 'asignada' }
    expect(servicios.documentos!.listarGuias).toHaveBeenLastCalledWith(1, filtro)
    expect(servicios.documentos!.descargarZip).toHaveBeenCalledWith(1, 'guias', filtro)
  })

  it('no busca con un rango invertido', async () => {
    const vista = await montar()
    const [desde, hasta] = vista.findAllComponents({ name: 'DatePicker' })
    await desde!.vm.$emit('update:modelValue', new Date(2026, 10, 5))
    await hasta!.vm.$emit('update:modelValue', new Date(2026, 10, 1))
    await asentar()

    expect(vista.get('#hasta-error').text()).toBe(
      'La fecha final debe ser igual o posterior a la inicial',
    )
    expect(vista.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    expect(boton(vista, 'Descargar todo lo filtrado').attributes('disabled')).toBeDefined()
  })

  it('sin resultados lo dice y no deja descargar', async () => {
    const vista = await montar([])

    expect(vista.text()).toContain('No hay guías que coincidan con los filtros')
    expect(boton(vista, 'Descargar todo lo filtrado').attributes('disabled')).toBeDefined()
  })

  it('muestra en un aviso el error de la descarga', async () => {
    const vista = await montar()
    const message = 'Se pueden exportar hasta 300 documentos a la vez y hay 301.'
    vi.mocked(servicios.documentos!.descargarZip).mockRejectedValueOnce(
      new AxiosError(message, AxiosError.ERR_BAD_REQUEST, undefined, undefined, {
        status: 422,
        data: { message },
      } as AxiosResponse),
    )

    await boton(vista, 'Descargar todo lo filtrado').trigger('click')
    await asentar()

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ severity: 'error', summary: message }),
    )
    expect(clic).not.toHaveBeenCalled()
  })
})
