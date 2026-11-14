import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ProyectosServicio } from '@/services/proyectos'
import type { FacturasServicio } from '@/services/facturas'
import FacturasView from '@/views/facturas/FacturasView.vue'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'
import type { GuiaFila } from '@/types/guias'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: unknown) => void>() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    facturas: null as FacturasServicio | null,
  },
}))

const { confirm } = vi.hoisted(() => ({
  confirm: {
    require: vi.fn<(opciones: { accept?: () => void; reject?: () => void }) => void>(),
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

vi.mock('@/services/facturas', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/facturas')>()
  return {
    ...real,
    get facturasServicio() {
      return servicios.facturas
    },
  }
})

const pdf = () => new File(['%PDF'], 'factura.pdf', { type: 'application/pdf' })

async function montar(rol: RolClave = 'oficina_tecnica', guiasExtra: GuiaFila[] = []) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearFacturasEnMemoria } = await import('@/services/facturas')
  servicios.proyectos = crearProyectosEnMemoria(async () => [1])
  servicios.facturas = crearFacturasEnMemoria(() => null, guiasExtra)
  // Dos facturas de UNICON: una cuadra con sus guías y otra se registró con diferencia.
  await servicios.facturas.registrar({
    proyecto_id: 1,
    proveedor_id: 1,
    numero: 'F001-00002803',
    fecha: '2026-10-28',
    archivo: pdf(),
    lineas: [{ resistencia_id: 4, volumen_m3: 14.5, precio_unitario: 329 }],
    igv: 858.69,
    guia_ids: [2, 3],
  })
  await servicios.facturas.registrar({
    proyecto_id: 1,
    proveedor_id: 1,
    numero: 'F001-00002790',
    fecha: '2026-10-27',
    archivo: pdf(),
    lineas: [{ resistencia_id: 3, volumen_m3: 9, precio_unitario: 291 }],
    igv: 471.42,
    guia_ids: [1],
    confirmado: true,
  })
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/facturas', name: 'facturas', component: FacturasView },
      { path: '/facturas/nueva', name: 'nueva-factura', component: { template: '<div />' } },
    ],
  })
  await router.push('/facturas')
  const vista = mount(FacturasView, {
    global: { plugins: [PrimeVue, pinia, router], stubs: { teleport: true } },
  })
  await asentar()
  return { vista, router }
}

enableAutoUnmount(afterEach)
beforeEach(() => {
  usarRelojFalso()
  toast.add.mockClear()
  confirm.require.mockReset()
})
afterEach(() => vi.useRealTimers())

describe('FacturasView (CU11)', () => {
  it('lista las facturas del proyecto, de la más reciente a la más antigua', async () => {
    const { vista } = await montar()

    const numeros = vista.findAll('tbody tr').map((tr) => tr.find('td span.font-medium').text())
    expect(numeros).toEqual(['F001-00002803', 'F001-00002790'])
    expect(vista.findAll('tbody tr')[1]!.text()).toContain('Volumen distinto')
  })

  it('muestra el costo real del proyecto: la suma de las facturas sin IGV', async () => {
    const { vista } = await montar()

    // 14.5 × 329 + 9 × 291
    expect(vista.get('[data-costo-real]').text()).toBe('S/ 7,389.50')
  })

  it('muestra el detalle con las guías vinculadas y su importe', async () => {
    const { vista } = await montar()
    await vista.get('button[aria-label="Ver detalle de la factura F001-00002803"]').trigger('click')
    await asentar()

    const detalle = vista.get('[data-detalle]').text()
    expect(detalle).toContain('T001-00003869')
    expect(detalle).toContain('2,467.50')
    expect(detalle).toContain('S/ 4,770.50')
  })

  it('Oficina técnica abre «Nueva factura»; el administrador solo consulta', async () => {
    const { vista, router } = await montar()
    await vista
      .findAll('button')
      .find((b) => b.text() === 'Nueva factura')!
      .trigger('click')
    await asentar()
    expect(router.currentRoute.value.name).toBe('nueva-factura')

    const admin = await montar('administrador')
    expect(admin.vista.findAll('button').some((b) => b.text() === 'Nueva factura')).toBe(false)
  })
})

// La factura F001-00002790 cobra 9 m³ de f'c 280 con la guía 1 (8.5 m³): faltan 0.5 m³.
describe('FacturasView: guías que faltan por vincular', () => {
  const abrir = async (vista: Awaited<ReturnType<typeof montar>>['vista'], numero: string) => {
    await vista.get(`button[aria-label="Ver detalle de la factura ${numero}"]`).trigger('click')
    await asentar()
  }

  it('avisa en la lista y en el detalle lo que falta; con el volumen exacto no avisa', async () => {
    const { vista } = await montar()
    const filas = vista.findAll('tbody tr')

    expect(filas[0]!.find('[data-pendiente-lista]').exists()).toBe(false)
    expect(filas[1]!.get('[data-pendiente-lista]').text()).toBe(
      'Faltan 0.50 m³ por vincular (facturado 9.00 m³, en guías 8.50 m³)',
    )

    await abrir(vista, 'F001-00002790')
    expect(vista.get('[data-pendiente]').text()).toContain(
      'Faltan 0.50 m³ por vincular (facturado 9.00 m³, en guías 8.50 m³)',
    )
    expect(vista.get('[data-pendiente]').text()).toContain("f'c 280")
    await abrir(vista, 'F001-00002803')
    expect(vista.find('[data-pendiente]').exists()).toBe(false)
  })

  // Una guía de UNICON de f'c 280 por 0.5 m³: justo lo que le falta a la factura F001-00002790.
  const guiaQueFalta = (volumen_m3 = 0.5): GuiaFila => ({
    id: 9,
    proyecto_id: 1,
    proveedor: { id: 1, ruc: '20100030595', razon_social: 'UNICON S.A.' },
    numero: 'T001-00003900',
    fecha: '2026-10-29',
    resistencia: { id: 3, fc_kg_cm2: 280, nombre: "f'c 280 kg/cm²" },
    codigo_producto: null,
    volumen_m3,
    placa: 'ABC-123',
    estado_asignacion: 'asignada',
    origen: 'ocr',
  })

  it('«Vincular guías» abre el diálogo con las guías por facturar del proveedor y proyecto de la factura', async () => {
    const { vista } = await montar('oficina_tecnica', [guiaQueFalta()])
    const pendientes = vi.spyOn(servicios.facturas!, 'guiasPorFacturar')
    await abrir(vista, 'F001-00002790')
    await vista.get('[data-vincular]').trigger('click')
    await asentar()

    expect(pendientes).toHaveBeenCalledWith(1, 1)
    expect(vista.findAll('[data-guia]').map((g) => g.text())).toEqual([
      expect.stringContaining('T001-00003900'),
    ])
  })

  it('suma la guía elegida a las ya vinculadas; con el volumen exacto guarda sin preguntar', async () => {
    const { vista } = await montar('oficina_tecnica', [guiaQueFalta()])
    await abrir(vista, 'F001-00002790')
    await vista.get('[data-vincular]').trigger('click')
    await asentar()
    await vista.get('input[type="checkbox"][id="guia-9"]').setValue(true)
    await vista
      .findAll('button')
      .find((b) => b.text() === 'Confirmar')!
      .trigger('click')
    await asentar()

    expect(confirm.require).not.toHaveBeenCalled()
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Guías vinculadas correctamente' }),
    )
    const detalle = vista.get('[data-detalle]').text()
    expect(detalle).toContain('T001-00003855')
    expect(detalle).toContain('T001-00003900')
    expect(vista.find('[data-pendiente]').exists()).toBe(false)
    expect(vista.find('[data-pendiente-lista]').exists()).toBe(false)
  })

  it('pide confirmar con el mismo mensaje si sigue sin coincidir y vincula al confirmar', async () => {
    const { vista } = await montar('oficina_tecnica', [guiaQueFalta(0.3)])
    confirm.require.mockImplementation((o) => o.accept?.())
    await abrir(vista, 'F001-00002790')
    await vista.get('[data-vincular]').trigger('click')
    await asentar()
    await vista.get('input[type="checkbox"][id="guia-9"]').setValue(true)
    await vista
      .findAll('button')
      .find((b) => b.text() === 'Confirmar')!
      .trigger('click')
    await asentar()

    expect(confirm.require).toHaveBeenCalledTimes(1)
    expect(confirm.require.mock.calls[0]![0]).toMatchObject({
      message:
        'El volumen facturado no coincide con las guías. ¿Vincular las guías de todas formas?',
    })
    expect(vista.get('[data-pendiente]').text()).toContain('Faltan 0.20 m³ por vincular')
  })

  it('solo Oficina técnica vincula; el administrador consulta', async () => {
    const { vista } = await montar('administrador')
    await abrir(vista, 'F001-00002790')

    expect(vista.find('[data-pendiente]').exists()).toBe(true)
    expect(vista.find('[data-vincular]').exists()).toBe(false)
  })
})
