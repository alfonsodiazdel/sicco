import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import { createPinia, setActivePinia } from 'pinia'
import type { ProyectosServicio } from '@/services/proyectos'
import type { GuiasServicio } from '@/services/guias'
import RegistrarGuiaView from '@/views/guias/RegistrarGuiaView.vue'
import { useAuthStore } from '@/stores/auth'
import type { Usuario } from '@/types/auth'
import type { CampoLeido, LecturaGuia, RegistroGuia } from '@/types/guias'
import { hoy } from '@/validaciones/guia'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: unknown) => void>() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    guias: null as GuiasServicio | null,
    lectura: null as LecturaGuia | null,
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

vi.mock('@/services/resistencias', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/resistencias')>()
  return { ...real, resistenciasServicio: real.crearResistenciasEnMemoria() }
})

vi.mock('@/services/guias', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/guias')>()
  return {
    ...real,
    get guiasServicio() {
      return servicios.guias
    },
  }
})

const campo = (valor: CampoLeido['valor'], revisar = false): CampoLeido => ({
  valor,
  confianza: revisar ? 0.5 : 0.95,
  revisar,
})

function lecturaUnicon(
  cambios: Partial<LecturaGuia['campos']> = {},
  proveedorId: number | null = 1,
  resistenciaId: number | null = 2,
): LecturaGuia {
  return {
    proveedor_id: proveedorId,
    resistencia_id: resistenciaId,
    campos: {
      numero: campo('T001-00012345'),
      fecha: campo('2026-10-30'),
      proveedor: campo('UNICON S.A.'),
      ruc: campo('20100030595'),
      codigo_producto: campo(null, true),
      descripcion_producto: campo("CONCRETO F'C 210"),
      resistencia: campo(210),
      volumen_m3: campo(8.5),
      placa: campo('ABC-123', true),
      ...cambios,
    },
  }
}

let registrados: RegistroGuia[] = []

async function montar() {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearGuiasEnMemoria } = await import('@/services/guias')
  servicios.proyectos = crearProyectosEnMemoria(async () => [1])
  const guias = crearGuiasEnMemoria(() => servicios.lectura)
  const registrar = guias.registrar
  guias.registrar = (datos) => {
    registrados.push(datos)
    return registrar(datos)
  }
  servicios.guias = guias
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: 'almacenero' } } as Usuario
  const vista = mount(RegistrarGuiaView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

async function tomarFoto(vista: VueWrapper) {
  const entrada = vista.get<HTMLInputElement>('#foto-guia')
  const archivo = new File(['jpg'], 'guia.jpg', { type: 'image/jpeg' })
  Object.defineProperty(entrada.element, 'files', { value: [archivo], configurable: true })
  await entrada.trigger('change')
  await asentar()
}

const boton = (vista: VueWrapper, texto: string) =>
  vista.findAll('button').find((b) => b.text() === texto)!

async function pulsar(vista: VueWrapper, texto: string) {
  await boton(vista, texto).trigger('click')
  await asentar()
}

async function guardar(vista: VueWrapper) {
  await vista.get('form').trigger('submit')
  await asentar()
}

const select = (vista: VueWrapper, id = 'proveedor_id') =>
  vista.findAllComponents(Select).find((s) => s.props('labelId') === id)!

const volumen = (vista: VueWrapper) =>
  vista.findAllComponents(InputNumber).find((n) => n.props('inputId') === 'volumen_m3')!

const avisos = (vista: VueWrapper) => vista.findAll('[data-aviso]').map((a) => a.text())

const errores = (vista: VueWrapper) =>
  Object.fromEntries(vista.findAll('small.text-red-600').map((e) => [e.attributes('id'), e.text()]))

enableAutoUnmount(afterEach)
beforeEach(usarRelojFalso)
afterEach(() => vi.useRealTimers())

describe('RegistrarGuiaView', () => {
  beforeEach(() => {
    toast.add.mockClear()
    registrados = []
    servicios.lectura = lecturaUnicon()
    vi.stubGlobal(
      'matchMedia',
      vi.fn(() => ({
        matches: false,
        addEventListener: vi.fn<() => void>(),
        removeEventListener: vi.fn<() => void>(),
      })),
    )
    // jsdom no trae URL.createObjectURL.
    vi.stubGlobal(
      'URL',
      Object.assign(URL, {
        createObjectURL: () => 'blob:foto',
        revokeObjectURL: vi.fn<() => void>(),
      }),
    )
  })

  it('pide la foto antes de leer o registrar', async () => {
    const vista = await montar()

    expect(vista.text()).toContain('Concreto recibido en Residencial Los Álamos')
    expect(boton(vista, 'Leer guía').attributes('disabled')).toBeDefined()
    expect(vista.get('[data-registrar-manual]').attributes('disabled')).toBeDefined()

    await tomarFoto(vista)
    expect(vista.get('img').attributes('src')).toBe('blob:foto')
    expect(boton(vista, 'Leer guía').attributes('disabled')).toBeUndefined()
  })

  it('muestra los datos leídos, marca los de baja confianza y registra la guía', async () => {
    const vista = await montar()
    await tomarFoto(vista)
    await pulsar(vista, 'Leer guía')

    expect(vista.get<HTMLInputElement>('#numero').element.value).toBe('T001-00012345')
    expect(vista.get<HTMLInputElement>('#fecha').element.value).toBe('2026-10-30')
    expect(select(vista).props('modelValue')).toBe(1)
    expect(select(vista, 'resistencia_id').props('modelValue')).toBe(2)
    expect(volumen(vista).props('modelValue')).toBe(8.5)
    expect(avisos(vista)).toEqual(['Verifique este dato'])

    await guardar(vista)

    expect(registrados).toEqual([
      expect.objectContaining({
        proyecto_id: 1,
        proveedor_id: 1,
        resistencia_id: 2,
        numero: 'T001-00012345',
        volumen_m3: 8.5,
        placa: 'ABC-123',
        foto_temporal: '00000000-0000-0000-0000-000000000000.jpg',
      }),
    ])
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ severity: 'success', summary: 'Guía registrada correctamente' }),
    )
    // Lista para la siguiente guía.
    expect(vista.find('form').exists()).toBe(false)
    expect(vista.find('img').exists()).toBe(false)
  })

  it('ofrece registrar el proveedor nuevo leído de la guía', async () => {
    servicios.lectura = lecturaUnicon(
      { proveedor: campo('Concretos del Sur S.A.C.'), ruc: campo('20601234561') },
      null,
    )
    const vista = await montar()
    await tomarFoto(vista)
    await pulsar(vista, 'Leer guía')

    expect(vista.text()).toContain('Concretos del Sur S.A.C. · RUC 20601234561 (nuevo)')
    await guardar(vista)

    expect(registrados[0]).toMatchObject({
      proveedor_ruc: '20601234561',
      proveedor_razon_social: 'Concretos del Sur S.A.C.',
    })
    expect(registrados[0]).not.toHaveProperty('proveedor_id')
  })

  it('pide elegir la resistencia que no está en el catálogo y envía el producto leído', async () => {
    servicios.lectura = lecturaUnicon(
      {
        codigo_producto: campo('70245NB5746'),
        descripcion_producto: campo('HOLCIM CLASICO'),
        resistencia: campo(null, true),
      },
      1,
      null,
    )
    const vista = await montar()
    await tomarFoto(vista)
    await pulsar(vista, 'Leer guía')

    expect(vista.get<HTMLInputElement>('#codigo_producto').element.value).toBe('70245NB5746')
    expect(avisos(vista)).toContain('Se leyó «HOLCIM CLASICO»; elija la resistencia')
    await guardar(vista)
    expect(errores(vista)).toEqual({ 'resistencia_id-error': 'Este dato es requerido' })

    await select(vista, 'resistencia_id').setValue(4)
    await guardar(vista)
    expect(registrados[0]).toMatchObject({
      resistencia_id: 4,
      codigo_producto: '70245NB5746',
      descripcion_producto: 'HOLCIM CLASICO',
    })
  })

  it('no envía la descripción leída con un código digitado o corregido a mano', async () => {
    // El OCR leyó la descripción, pero no el código: el almacenero lo digita.
    const vista = await montar()
    await tomarFoto(vista)
    await pulsar(vista, 'Leer guía')
    await vista.get('#codigo_producto').setValue('70280NB5746')
    await select(vista, 'resistencia_id').setValue(4)
    await guardar(vista)

    expect(registrados[0]).toMatchObject({ codigo_producto: '70280NB5746' })
    expect(registrados[0]).not.toHaveProperty('descripcion_producto')
  })

  it('envía la descripción leída si el código es el leído, aunque cambie la forma de escribirlo', async () => {
    servicios.lectura = lecturaUnicon({
      codigo_producto: campo('70245NB5746'),
      descripcion_producto: campo('HOLCIM CLASICO'),
    })
    const vista = await montar()
    await tomarFoto(vista)
    await pulsar(vista, 'Leer guía')
    await vista.get('#codigo_producto').setValue('70245nb 5746')
    await guardar(vista)

    expect(registrados[0]).toMatchObject({ descripcion_producto: 'HOLCIM CLASICO' })
  })

  it('registra como manual la guía cuya lectura se descartó, sin volver a subir la foto', async () => {
    const vista = await montar()
    await tomarFoto(vista)
    await pulsar(vista, 'Leer guía')
    await pulsar(vista, 'Volver a la foto')
    await vista.get('[data-registrar-manual]').trigger('click')
    await asentar()
    await vista.get('#numero').setValue('T001-00000777')
    await select(vista).setValue(2)
    await select(vista, 'resistencia_id').setValue(3)
    await volumen(vista).setValue(7)
    await vista.get('#codigo_producto').setValue('22259')
    await guardar(vista)

    expect(registrados[0]).toMatchObject({ foto_temporal: expect.any(String), manual: true })
    expect(registrados[0]).not.toHaveProperty('foto')
    expect(registrados[0]).not.toHaveProperty('descripcion_producto')
  })

  it('deja vacíos los datos no identificados y pide completarlos', async () => {
    servicios.lectura = lecturaUnicon({ volumen_m3: campo(null, true) })
    const vista = await montar()
    await tomarFoto(vista)
    await pulsar(vista, 'Leer guía')

    expect(avisos(vista)).toContain('No se pudo leer; ingrese este dato')
    await guardar(vista)

    expect(errores(vista)).toEqual({ 'volumen_m3-error': 'Este dato es requerido' })
    expect(registrados).toEqual([])
  })

  it('pasa al registro manual con la foto adjunta si no se pudo leer', async () => {
    servicios.lectura = null
    const vista = await montar()
    await tomarFoto(vista)
    await pulsar(vista, 'Leer guía')

    expect(vista.get('[data-sin-lectura]').text()).toContain('No se pudo leer la guía')
    expect(vista.get<HTMLInputElement>('#numero').element.value).toBe('')
    expect(vista.get<HTMLInputElement>('#fecha').element.value).toBe(hoy())
  })

  it('registra manualmente y no repite el número del mismo proveedor', async () => {
    const vista = await montar()
    const registrar = async () => {
      await tomarFoto(vista)
      await vista.get('[data-registrar-manual]').trigger('click')
      await asentar()
      await vista.get('#numero').setValue('T001-00000777')
      await select(vista).setValue(2)
      await select(vista, 'resistencia_id').setValue(3)
      await volumen(vista).setValue(7)
      await guardar(vista)
    }

    await registrar()
    expect(registrados[0]).toMatchObject({
      proveedor_id: 2,
      resistencia_id: 3,
      fecha: hoy(),
      foto: expect.any(Blob),
    })
    expect(registrados[0]).not.toHaveProperty('foto_temporal')

    await registrar()
    expect(errores(vista)).toEqual({ 'numero-error': 'La guía ya fue registrada' })
  })
})
