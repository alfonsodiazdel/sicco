import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ReportesView from '@/views/reportes/ReportesView.vue'
import router from '@/router'

enableAutoUnmount(afterEach)
beforeEach(() => setActivePinia(createPinia()))

describe('ReportesView', () => {
  it('ofrece las tarjetas «Reporte EAT» y «Corte de mes»', async () => {
    const enMemoria = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/reportes', component: ReportesView },
        { path: '/reportes/eat', component: { template: '<div />' } },
        { path: '/reportes/corte-mes', component: { template: '<div />' } },
      ],
    })
    await enMemoria.push('/reportes')
    const vista = mount(ReportesView, { global: { plugins: [enMemoria] } })

    const enlaces = vista.findAll('a')
    expect(enlaces.map((a) => [a.text(), a.attributes('href')])).toEqual([
      [expect.stringContaining('Reporte EAT'), '/reportes/eat'],
      [expect.stringContaining('Corte de mes'), '/reportes/corte-mes'],
    ])
  })

  it('las rutas de reportes no caen en la vista provisional y piden proyecto donde corresponde', () => {
    const reportes = router.resolve('/reportes')
    const eat = router.resolve('/reportes/eat')

    expect(reportes.name).toBe('reportes')
    expect(reportes.meta).toMatchObject({ roles: ['oficina_tecnica', 'gerencia'] })
    expect(eat.name).toBe('reporte-eat')
    expect(eat.meta).toMatchObject({
      roles: ['oficina_tecnica', 'gerencia'],
      titulo: 'Reporte EAT',
      porProyecto: true,
    })
  })
})
