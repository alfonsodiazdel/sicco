import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ProyectosServicio } from '@/services/proyectos'
import type { FacturasServicio } from '@/services/facturas'
import NuevaFacturaView from '@/views/facturas/NuevaFacturaView.vue'
import { useAuthStore } from '@/stores/auth'
import type { Usuario } from '@/types/auth'
import type { LecturaFactura, RegistroFactura } from '@/types/facturas'

const { toast, confirm, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: unknown) => void>() },
  confirm: {
    require:
      vi.fn<(opciones: { message: string; accept: () => void; reject: () => void }) => void>(),
  },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    facturas: null as FacturasServicio | null,
  },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))
vi.mock('primevue/useconfirm', () => ({ useConfirm: () => confirm }))

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

vi.mock('@/services/facturas', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/facturas')>()
  return {
    ...real,
    get facturasServicio() {
      return servicios.facturas
    },
  }
})

let registrados: RegistroFactura[] = []

async function montar(lectura: LecturaFactura | null = null) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearFacturasEnMemoria } = await import('@/services/facturas')
  servicios.proyectos = crearProyectosEnMemoria(async () => [1])
  const facturas = crearFacturasEnMemoria(() => lectura)
  const registrar = facturas.registrar
  facturas.registrar = (datos) => {
    registrados.push(datos)
    return registrar(datos)
  }
  servicios.facturas = facturas
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: 'oficina_tecnica' } } as Usuario
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/facturas/nueva', name: 'nueva-factura', component: NuevaFacturaView },
      { path: '/facturas', name: 'facturas', component: { template: '<div />' } },
    ],
  })
  await router.push('/facturas/nueva')
  const vista = mount(NuevaFacturaView, {
    global: { plugins: [PrimeVue, pinia, router], stubs: { teleport: true } },
  })
  await asentar()
  return { vista, router }
}

const select = (vista: VueWrapper, id: string) =>
  vista.findAllComponents(Select).find((s) => s.props('labelId') === id)!

const numero = (vista: VueWrapper, id: string) =>
  vista.findAllComponents(InputNumber).find((n) => n.props('inputId') === id)!

async function elegir(vista: VueWrapper, id: string, valor: number) {
  await select(vista, id).vm.$emit('update:modelValue', valor)
  await asentar()
}

async function digitar(vista: VueWrapper, id: string, valor: number) {
  await numero(vista, id).vm.$emit('update:modelValue', valor)
  await asentar()
}

async function adjuntar(
  vista: VueWrapper,
  archivo = new File(['%PDF'], 'F001-2803.pdf', { type: 'application/pdf' }),
) {
  const entrada = vista.get<HTMLInputElement>('#archivo')
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

async function ingresarManualmente(vista: VueWrapper) {
  await vista.get('[data-ingresar-manual]').trigger('click')
  await asentar()
}

/** CU11 pasos 1–2 con la factura F001-2803: 8.5 m³ de f'c 280 y 14.5 m³ de f'c 350. */
async function llenarFactura(vista: VueWrapper) {
  await adjuntar(vista)
  await ingresarManualmente(vista)
  await vista.get('#numero').setValue('F001-00002803')
  await vista.get('#fecha').setValue('2026-10-28')
  await elegir(vista, 'proveedor_id', 1)
  await elegir(vista, 'resistencia-0', 3)
  await digitar(vista, 'volumen-0', 8.5)
  await digitar(vista, 'precio-0', 291)
  await pulsar(vista, 'Agregar resistencia')
  await elegir(vista, 'resistencia-1', 4)
  await digitar(vista, 'volumen-1', 14.5)
  await digitar(vista, 'precio-1', 329)
}

/** CU12: abre «Vincular guías», marca las guías dadas y confirma. */
async function vincular(vista: VueWrapper, ids: number[]) {
  await vista.get('[data-vincular]').trigger('click')
  await asentar()
  for (const id of ids) {
    await vista.get(`#guia-${id}`).trigger('change')
  }
  await asentar()
  await pulsar(vista, 'Confirmar')
}

async function guardar(vista: VueWrapper) {
  await vista.get('form').trigger('submit')
  await asentar()
}

enableAutoUnmount(afterEach)
beforeEach(() => {
  usarRelojFalso()
  vi.setSystemTime(new Date(2026, 10, 2, 10))
  registrados = []
  toast.add.mockClear()
  confirm.require.mockReset()
  vi.stubGlobal(
    'matchMedia',
    vi.fn<() => object>(() => ({
      matches: false,
      addEventListener: vi.fn<() => void>(),
      removeEventListener: vi.fn<() => void>(),
    })),
  )
})
afterEach(() => vi.useRealTimers())

describe('NuevaFacturaView (CU11)', () => {
  it('calcula el importe de cada línea, el subtotal, el IGV al 18 % y el total', async () => {
    const { vista } = await montar()
    await llenarFactura(vista)

    expect(vista.findAll('[data-importe]').map((i) => i.text())).toEqual(['2,473.50', '4,770.50'])
    expect(vista.get('[data-volumen-facturado]').text()).toBe('23.00 m³')
    expect(vista.get('[data-subtotal]').text()).toBe('S/ 7,244.00')
    expect(vista.get('[data-total]').text()).toBe('S/ 8,547.92')
  })

  it('lista las guías del proveedor por facturar y suma lo marcado (CU12)', async () => {
    const { vista } = await montar()
    await llenarFactura(vista)
    await vista.get('[data-vincular]').trigger('click')
    await asentar()

    // Solo las del proveedor elegido.
    expect(vista.findAll('[data-guia]')).toHaveLength(3)
    await vista.get('#guia-1').trigger('change')
    await vista.get('#guia-2').trigger('change')
    await asentar()
    expect(vista.get('[data-seleccionado]').text()).toBe('Seleccionado: 16.00 m³ – S/ 4,941.00')
  })

  it('avisa si el proveedor no tiene guías pendientes', async () => {
    const { vista } = await montar()
    await llenarFactura(vista)
    await vincular(vista, [1, 2, 3])
    await guardar(vista)

    await vista.get('[data-vincular]').trigger('click')
    await asentar()
    expect(vista.get('[data-sin-pendientes]').text()).toBe('No hay guías pendientes del proveedor')
  })

  it('registra la factura con sus guías cuando el volumen coincide', async () => {
    const { vista, router } = await montar()
    await llenarFactura(vista)
    await vincular(vista, [1, 2, 3])

    expect(vista.findAll('[data-vinculada]')).toHaveLength(3)
    expect(vista.find('[data-coinciden]').exists()).toBe(true)
    await guardar(vista)

    expect(confirm.require).not.toHaveBeenCalled()
    expect(registrados).toHaveLength(1)
    expect(registrados[0]).toMatchObject({
      proyecto_id: 1,
      proveedor_id: 1,
      numero: 'F001-00002803',
      fecha: '2026-10-28',
      igv: 1303.92,
      guia_ids: [1, 2, 3],
      confirmado: false,
      lineas: [
        { resistencia_id: 3, volumen_m3: 8.5, precio_unitario: 291 },
        { resistencia_id: 4, volumen_m3: 14.5, precio_unitario: 329 },
      ],
    })
    const registro = registrados[0]!
    expect('archivo' in registro && registro.archivo.name).toBe('F001-2803.pdf')
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ severity: 'success', summary: 'Factura registrada correctamente' }),
    )
    expect(router.currentRoute.value.name).toBe('facturas')
  })

  it('pide confirmar si el volumen facturado no coincide con las guías', async () => {
    const { vista, router } = await montar()
    await llenarFactura(vista)
    await vincular(vista, [1, 2])

    expect(vista.get('[data-no-coinciden]').text()).toContain(
      "f'c 350 kg/cm²: facturado 14.50 m³, guías 7.50 m³",
    )

    confirm.require.mockImplementationOnce((o: { reject: () => void }) => o.reject())
    await guardar(vista)
    expect(confirm.require).toHaveBeenCalledWith(
      expect.objectContaining({
        message:
          'El volumen facturado no coincide con las guías. ¿Registrar la factura de todas formas?',
      }),
    )
    expect(router.currentRoute.value.name).toBe('nueva-factura')

    confirm.require.mockImplementationOnce((o: { accept: () => void }) => o.accept())
    await guardar(vista)
    expect(registrados[registrados.length - 1]!.confirmado).toBe(true)
    expect(router.currentRoute.value.name).toBe('facturas')
  })

  it('muestra «La factura ya fue registrada» si el número se repite para el proveedor', async () => {
    const { vista } = await montar()
    await servicios.facturas!.registrar({
      proyecto_id: 1,
      proveedor_id: 1,
      numero: 'F001-00002803',
      fecha: '2026-10-28',
      archivo: new File(['%PDF'], 'otra.pdf', { type: 'application/pdf' }),
      lineas: [{ resistencia_id: 3, volumen_m3: 8.5, precio_unitario: 291 }],
      igv: 445.23,
      guia_ids: [1],
    })
    await llenarFactura(vista)
    await vincular(vista, [2, 3])
    confirm.require.mockImplementationOnce((o: { accept: () => void }) => o.accept())
    await guardar(vista)

    expect(vista.text()).toContain('La factura ya fue registrada')
  })

  it('valida los datos requeridos antes de enviar', async () => {
    const { vista } = await montar()
    await ingresarManualmente(vista)
    await guardar(vista)

    expect(registrados).toHaveLength(0)
    const texto = vista.text()
    expect(texto).toContain('Este dato es requerido')
    expect(texto).toContain('Vincule al menos una guía')
  })

  it('rechaza un archivo que no es imagen ni PDF', async () => {
    const { vista } = await montar()
    await adjuntar(vista, new File(['x'], 'factura.docx', { type: 'application/msword' }))

    expect(vista.text()).toContain('Adjunte una imagen (JPG, PNG o WebP) o un PDF de hasta 10 MB')
  })

  it('pide el proveedor antes de vincular guías', async () => {
    const { vista } = await montar()
    await ingresarManualmente(vista)
    await vista.get('[data-vincular]').trigger('click')
    await asentar()

    expect(vista.text()).toContain('Elija el proveedor para ver sus guías')
    expect(vista.find('[data-guia]').exists()).toBe(false)
  })

  it('al cambiar de proveedor quita las guías vinculadas', async () => {
    const { vista } = await montar()
    await llenarFactura(vista)
    await vincular(vista, [1])
    expect(vista.findAll('[data-vinculada]')).toHaveLength(1)

    await elegir(vista, 'proveedor_id', 2)
    expect(vista.findAll('[data-vinculada]')).toHaveLength(0)
  })

  it('en el registro manual propone las guías que completan el volumen', async () => {
    const { vista } = await montar()
    await llenarFactura(vista)
    await vista.get('[data-vincular]').trigger('click')
    await asentar()
    await vista.get('[data-proponer]').trigger('click')
    await asentar()

    expect(vista.get('[data-seleccionado]').text()).toBe('Seleccionado: 23.00 m³ – S/ 7,244.00')
  })
})

const campo = (valor: string | number | null, revisar = false) => ({
  valor,
  confianza: revisar ? 0.5 : 0.97,
  revisar,
})

/** Lo leído de la F001-2803 de UNICON (proveedor 1), con un cargo de bombeo de S/ 800. */
function lecturaFactura(cambios: Partial<LecturaFactura> = {}): LecturaFactura {
  return {
    campos: {
      numero: campo('F001-00002803'),
      fecha: campo('2026-10-28'),
      proveedor: campo('UNICON S.A.'),
      ruc: campo('20100030595'),
      subtotal: campo(8044),
      igv: campo(1447.92),
      total: campo(9491.92),
    },
    proveedor_id: 1,
    lineas: [
      {
        resistencia_id: 3,
        volumen_m3: 8.5,
        precio_unitario: 291,
        descripcion: "CONCRETO F'C 280",
        codigo_producto: null,
        revisar: false,
      },
      {
        resistencia_id: 4,
        volumen_m3: 14.5,
        precio_unitario: 329,
        descripcion: "CONCRETO F'C 350",
        codigo_producto: null,
        revisar: false,
      },
    ],
    cargos: [{ descripcion: 'SERVICIO DE BOMBEO', importe: 800, revisar: false }],
    guias: { sugeridas: [1, 2, 3], avisos: [] },
    ...cambios,
  }
}

async function leerFactura(vista: VueWrapper) {
  await adjuntar(vista)
  await pulsar(vista, 'Leer factura')
}

describe('NuevaFacturaView con OCR', () => {
  it('llena el formulario con lo leído, separa los otros cargos y marca las guías citadas', async () => {
    const { vista } = await montar(lecturaFactura())
    await leerFactura(vista)

    expect(vista.find('[data-leida]').exists()).toBe(true)
    expect(vista.get<HTMLInputElement>('#numero').element.value).toBe('F001-00002803')
    expect(select(vista, 'proveedor_id').props('modelValue')).toBe(1)
    expect(vista.findAll('[data-linea]')).toHaveLength(2)
    expect(vista.get<HTMLInputElement>('#cargo-0').element.value).toBe('SERVICIO DE BOMBEO')
    // El bombeo suma al costo, no al volumen.
    expect(vista.get('[data-volumen-facturado]').text()).toBe('23.00 m³')
    expect(vista.get('[data-subtotal]').text()).toBe('S/ 8,044.00')
    expect(vista.get('[data-total]').text()).toBe('S/ 9,491.92')
    expect(vista.find('[data-total-distinto]').exists()).toBe(false)
    expect(vista.get('[data-propuesta]').text()).toBe('Se marcaron las guías que cita la factura.')
    expect(vista.findAll('[data-vinculada]')).toHaveLength(3)
    expect(vista.find('[data-coinciden]').exists()).toBe(true)
  })

  it('registra la factura leída sin volver a subir el archivo', async () => {
    const { vista, router } = await montar(lecturaFactura())
    await leerFactura(vista)
    await guardar(vista)

    const registro = registrados[0]!
    expect(registro).toMatchObject({
      archivo_temporal: '00000000-0000-0000-0000-000000000000.pdf',
      numero: 'F001-00002803',
      igv: 1447.92,
      guia_ids: [1, 2, 3],
      cargos: [{ descripcion: 'SERVICIO DE BOMBEO', importe: 800 }],
    })
    expect('archivo' in registro).toBe(false)
    expect(router.currentRoute.value.name).toBe('facturas')
  })

  it('si la factura no cita guías, propone las que completan el volumen', async () => {
    const { vista } = await montar(lecturaFactura({ guias: { sugeridas: [], avisos: [] } }))
    await leerFactura(vista)

    expect(vista.get('[data-propuesta]').text()).toContain('La factura no cita guías')
    expect(vista.findAll('[data-vinculada]').map((f) => f.find('td').text())).toEqual([
      'T001-00003855',
      'T001-00003869',
      'T001-00003875',
    ])
  })

  it('avisa de las guías citadas que no pudo proponer', async () => {
    const aviso = 'La factura cita la guía T001-00009999, que no está registrada'
    const { vista } = await montar(lecturaFactura({ guias: { sugeridas: [1], avisos: [aviso] } }))
    await leerFactura(vista)

    expect(vista.get('[data-avisos-guias]').text()).toBe(aviso)
    expect(vista.findAll('[data-vinculada]')).toHaveLength(1)
  })

  it('marca los datos por revisar hasta que se corrigen', async () => {
    const lectura = lecturaFactura()
    lectura.campos.numero = campo('F001-0002803', true)
    lectura.lineas[0]!.revisar = true
    const { vista } = await montar(lectura)
    await leerFactura(vista)

    expect(vista.findAll('[data-aviso]').map((a) => a.text())).toEqual(['Verifique este dato'])
    expect(vista.get('[data-aviso-linea]').text()).toBe(
      "Se leyó «CONCRETO F'C 280»; verifique la línea",
    )
    await vista.get('#numero').setValue('F001-00002803')
    expect(vista.findAll('[data-aviso]')).toHaveLength(0)
  })

  it('avisa si el total calculado no es el de la factura', async () => {
    const lectura = lecturaFactura()
    lectura.campos.total = campo(9500)
    const { vista } = await montar(lectura)
    await leerFactura(vista)

    expect(vista.get('[data-total-distinto]').text()).toBe(
      'La factura indica S/ 9,500.00; revise los importes',
    )
  })

  it('pide elegir el proveedor si su RUC no está registrado', async () => {
    const { vista } = await montar(lecturaFactura({ proveedor_id: null }))
    await leerFactura(vista)

    expect(vista.get('[data-aviso]').text()).toBe(
      'Se leyó «UNICON S.A. · RUC 20100030595», que no está registrado; elija el proveedor',
    )
    expect(vista.findAll('[data-vinculada]')).toHaveLength(0)
  })

  it('si el OCR no lee la factura, pasa al ingreso manual con el archivo adjunto', async () => {
    const { vista } = await montar(null)
    await leerFactura(vista)

    expect(vista.find('[data-sin-lectura]').exists()).toBe(true)
    expect(vista.get('[data-archivo-adjunto]').text()).toContain('F001-2803.pdf')
    expect(vista.get<HTMLInputElement>('#numero').element.value).toBe('')
  })
})
