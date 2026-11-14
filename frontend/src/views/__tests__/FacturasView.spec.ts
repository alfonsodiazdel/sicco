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
import type { VueWrapper } from '@vue/test-utils'

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

vi.mock('@/services/facturas', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/facturas')>()
  return {
    ...real,
    get facturasServicio() {
      return servicios.facturas
    },
  }
})

const boton = (vista: VueWrapper, texto: string) =>
  vista.findAll('button').find((b) => b.text() === texto)!

async function pulsar(vista: VueWrapper, texto: string) {
  await boton(vista, texto).trigger('click')
  await asentar()
}

const pdf = () => new File(['%PDF'], 'factura.pdf', { type: 'application/pdf' })

async function montar(
  rol: RolClave = 'oficina_tecnica',
  preparar?: (facturas: FacturasServicio) => Promise<unknown>,
) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearFacturasEnMemoria } = await import('@/services/facturas')
  servicios.proyectos = crearProyectosEnMemoria(async () => [1])
  servicios.facturas = crearFacturasEnMemoria()
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
  await preparar?.(servicios.facturas)
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

  it('quita una guía de la factura y pide confirmar si el volumen deja de coincidir', async () => {
    const { vista } = await montar()
    await vista.get('button[aria-label="Ver detalle de la factura F001-00002803"]').trigger('click')
    await asentar()

    const quitarGuia3 = async () => {
      await vista.get('[data-vincular]').trigger('click')
      await asentar()
      // Sus dos guías; la guía 1 es de la F001-2790 y no aparece.
      expect(vista.findAll('[data-guia]')).toHaveLength(2)
      await vista.get('#guia-3').trigger('change')
      await asentar()
      await pulsar(vista, 'Confirmar')
    }

    confirm.require.mockImplementationOnce((o: { reject: () => void }) => o.reject())
    await quitarGuia3()
    expect(confirm.require).toHaveBeenCalledWith(
      expect.objectContaining({
        message:
          'El volumen facturado no coincide con las guías. ¿Guardar las guías de todas formas?',
      }),
    )
    expect(vista.get('[data-detalle]').text()).toContain('T001-00003875')

    confirm.require.mockImplementationOnce((o: { accept: () => void }) => o.accept())
    await quitarGuia3()
    expect(vista.get('[data-detalle]').text()).not.toContain('T001-00003875')
    expect(vista.findAll('tbody tr')[0]!.text()).toContain('Volumen distinto')
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Guías de la factura actualizadas correctamente' }),
    )
    // La guía quitada vuelve a quedar por facturar.
    expect((await servicios.facturas!.guiasPorFacturar(1, 1)).map((g) => g.id)).toEqual([3])
  })

  it('agrega una guía que quedó pendiente y la factura deja de tener volumen distinto', async () => {
    // La F001-2803 quedó sin la guía 3 (7 m³ de f'c 350): se guardó con diferencia.
    const { vista } = await montar('oficina_tecnica', (f) => f.actualizarGuias(1, [2], true))
    expect(vista.findAll('tbody tr')[0]!.text()).toContain('Volumen distinto')
    await vista.get('button[aria-label="Ver detalle de la factura F001-00002803"]').trigger('click')
    await asentar()
    await vista.get('[data-vincular]').trigger('click')
    await asentar()
    await vista.get('#guia-3').trigger('change')
    await asentar()
    await pulsar(vista, 'Confirmar')

    expect(confirm.require).not.toHaveBeenCalled()
    expect(vista.get('[data-detalle]').text()).toContain('T001-00003875')
    expect(vista.findAll('tbody tr')[0]!.text()).not.toContain('Volumen distinto')
  })

  it('avisa los m³ que faltan por vincular en la lista y en el detalle, y el aviso desaparece al vincularlos', async () => {
    // La F001-2803 factura 14.5 m³ de f'c 350 y solo tiene la guía 2 (7.5 m³).
    const { vista } = await montar('oficina_tecnica', (f) => f.actualizarGuias(1, [2], true))
    const aviso = 'Faltan 7 m³ por vincular (facturado 14.50 m³, en guías 7.50 m³)'
    // La F001-2790 factura 9 m³ y su guía trae 8.5 m³.
    const otra = 'Faltan 0.50 m³ por vincular (facturado 9 m³, en guías 8.50 m³)'
    expect(vista.findAll('[data-pendiente-lista]').map((a) => a.text())).toEqual([aviso, otra])

    await vista.get('button[aria-label="Ver detalle de la factura F001-00002803"]').trigger('click')
    await asentar()
    const detalle = vista.get('[data-pendiente]').text()
    expect(detalle).toContain(aviso)
    expect(detalle).toContain('faltan 7 m³ (facturado 14.50 m³, en guías 7.50 m³)')

    await vista.get('[data-vincular]').trigger('click')
    await asentar()
    await vista.get('#guia-3').trigger('change')
    await asentar()
    await pulsar(vista, 'Confirmar')

    expect(vista.find('[data-pendiente]').exists()).toBe(false)
    expect(vista.findAll('[data-pendiente-lista]').map((a) => a.text())).toEqual([otra])
  })

  it('el administrador no cambia las guías de la factura', async () => {
    const { vista } = await montar('administrador')
    await vista.get('button[aria-label="Ver detalle de la factura F001-00002803"]').trigger('click')
    await asentar()
    expect(vista.find('[data-vincular]').exists()).toBe(false)
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
