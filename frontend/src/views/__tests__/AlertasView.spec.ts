import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError, AxiosHeaders } from 'axios'
import type { AlertasServicio } from '@/services/alertas'
import type { Alerta } from '@/types/alertas'
import AlertasView from '@/views/AlertasView.vue'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, servicio } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: object) => void>() },
  servicio: {
    listar: vi.fn<AlertasServicio['listar']>(),
    pendientes: vi.fn<AlertasServicio['pendientes']>(),
    revisar: vi.fn<AlertasServicio['revisar']>(),
  } satisfies AlertasServicio,
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))
vi.mock('@/services/alertas', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/alertas')>()
  return { ...real, alertasServicio: servicio }
})

const alerta = (id: number, cambios: Partial<Alerta> = {}): Alerta => ({
  id,
  // 15:30 en Lima.
  fecha: '2026-11-08T20:30:00+00:00',
  estado: 'pendiente',
  exceso_m3: 1.2,
  proyecto: { id: 1, codigo: 'PRY-001', nombre: 'Residencial Los Álamos' },
  sector: {
    id: 10 + id,
    nombre: 'Sector A',
    nivel: 'Nivel 3',
    elemento: 'Losa · f’c 210',
    presupuestado_m3: 10,
    factor_desperdicio: 5,
    limite_m3: 10.5,
    vaciado_m3: 11.7,
  },
  correo_enviado_at: '2026-11-08T20:31:00+00:00',
  revisada_at: null,
  revisada_por: null,
  ...cambios,
})

async function montar(alertas: Alerta[], rol: RolClave = 'ingeniero_produccion') {
  servicio.listar.mockResolvedValue(alertas)
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  const vista = mount(AlertasView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

async function abrirDetalle(vista: Awaited<ReturnType<typeof montar>>, fila = 0) {
  await vista.findAll('tbody tr')[fila]!.get('button[title="Ver detalle"]').trigger('click')
  await asentar()
}

const botonRevisar = (vista: Awaited<ReturnType<typeof montar>>) =>
  vista.findAll('button').find((b) => b.text() === 'Marcar como revisada')

enableAutoUnmount(afterEach)
beforeEach(() => {
  usarRelojFalso()
  toast.add.mockClear()
  servicio.listar.mockReset()
  servicio.revisar.mockReset()
  servicio.pendientes.mockReset().mockResolvedValue(0)
  vi.stubGlobal(
    'matchMedia',
    vi.fn<() => object>(() => ({
      matches: false,
      addEventListener: vi.fn<() => void>(),
      removeEventListener: vi.fn<() => void>(),
    })),
  )
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('AlertasView (CU14)', () => {
  it('muestra el aviso de la última pendiente y la tabla con fecha, proyecto, sector, exceso y estado', async () => {
    const vista = await montar([
      alerta(2),
      alerta(1, { estado: 'revisada', correo_enviado_at: null }),
    ])

    expect(servicio.listar).toHaveBeenCalledWith({ estado: undefined })
    expect(vista.get('[data-aviso]').text()).toContain(
      'Nivel 3 – Sector A – Losa · f’c 210 superó su límite en 1.20 m³',
    )
    const celdas = vista
      .findAll('tbody tr')[0]!
      .findAll('td')
      .map((td) => td.text())
    expect(celdas[0]).toBe('08/11/2026, 03:30 p. m.')
    expect(celdas[1]).toContain('PRY-001')
    expect(celdas[2]).toContain('Nivel 3 – Sector A')
    expect(celdas[3]).toBe('1.20')
    expect(celdas[4]).toBe('Pendiente')
    // Flujo alternativo 1: el correo que no salió se ve en la tabla.
    expect(vista.findAll('tbody tr')[1]!.text()).toContain('Correo por enviar')
  })

  it('sin pendientes no muestra el aviso', async () => {
    const vista = await montar([alerta(1, { estado: 'revisada' })])

    expect(vista.find('[data-aviso]').exists()).toBe(false)
  })

  it('filtra por estado', async () => {
    const vista = await montar([alerta(1)])

    await vista.findComponent({ name: 'Select' }).vm.$emit('update:modelValue', 'pendiente')
    await asentar()

    expect(servicio.listar).toHaveBeenLastCalledWith({ estado: 'pendiente' })
  })

  it('abre el detalle con el límite del sector y la marca como revisada', async () => {
    const vista = await montar([alerta(1)])
    servicio.revisar.mockResolvedValue({
      alerta: alerta(1, {
        estado: 'revisada',
        revisada_at: '2026-11-08T21:00:00+00:00',
        revisada_por: 'Ana Paz',
      }),
      message: 'Alerta marcada como revisada',
    })

    await abrirDetalle(vista)
    const detalle = vista.get('[data-detalle]').text()
    expect(detalle).toContain('10.50 m³')
    expect(detalle).toContain('11.70 m³')
    expect(detalle).toContain('5.00 %')

    await botonRevisar(vista)!.trigger('click')
    await asentar()

    expect(servicio.revisar).toHaveBeenCalledWith(1)
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ severity: 'success', summary: 'Alerta marcada como revisada' }),
    )
    expect(vista.get('tbody tr').text()).toContain('Revisada')
    expect(vista.find('[data-detalle]').exists()).toBe(false)
    // El contador del ícono se refresca.
    expect(servicio.pendientes).toHaveBeenCalledTimes(2)
  })

  it('si otro usuario ya la revisó, lo avisa y trae el estado actual', async () => {
    const vista = await montar([alerta(1)])
    servicio.revisar.mockRejectedValue(
      new AxiosError('409', 'ERR', undefined, undefined, {
        status: 409,
        data: { message: 'La alerta ya fue revisada' },
        statusText: 'Conflict',
        headers: {},
        config: { headers: new AxiosHeaders() },
      }),
    )

    await abrirDetalle(vista)
    servicio.listar.mockResolvedValue([alerta(1, { estado: 'revisada' })])
    await botonRevisar(vista)!.trigger('click')
    await asentar()

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ severity: 'error', summary: 'La alerta ya fue revisada' }),
    )
    expect(vista.get('tbody tr').text()).toContain('Revisada')
  })

  it('una revisada no ofrece el botón y dice quién la revisó', async () => {
    const vista = await montar([
      alerta(1, {
        estado: 'revisada',
        revisada_at: '2026-11-08T21:00:00+00:00',
        revisada_por: 'Ana Paz',
      }),
    ])

    await abrirDetalle(vista)

    expect(botonRevisar(vista)).toBeUndefined()
    expect(vista.get('[data-detalle]').text()).toContain('por Ana Paz')
  })

  it('el administrador la consulta pero no la marca', async () => {
    const vista = await montar([alerta(1)], 'administrador')

    await abrirDetalle(vista)

    expect(vista.find('[data-detalle]').exists()).toBe(true)
    expect(botonRevisar(vista)).toBeUndefined()
  })
})
