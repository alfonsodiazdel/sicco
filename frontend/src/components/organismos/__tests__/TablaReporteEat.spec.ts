import { describe, expect, it } from 'vitest'
import { mount, type DOMWrapper } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import TablaReporteEat from '@/components/organismos/TablaReporteEat.vue'
import type { FilaAparteEat, FilaEat, MontosEat, ReporteEat } from '@/types/reportes'

type Cuatro = [number, number, number, number]
type Fila = Omit<DOMWrapper<Element>, 'exists'>

// Escenario E2 (corte 2026-10-31), calculado a mano en el seguimiento de la tarea.
const montos = (
  [presupuesto_m3, presupuesto_soles, consumido_m3, consumido_soles]: Cuatro,
  [saldo_m3, saldo_soles, eat_m3, eat_soles]: Cuatro,
  sobrecosto: boolean,
): MontosEat => ({
  presupuesto_m3,
  presupuesto_soles,
  consumido_m3,
  consumido_soles,
  saldo_m3,
  saldo_soles,
  eat_m3,
  eat_soles,
  sobrecosto,
})

const fila = (partida_id: number, item: string, etiqueta: string, m: MontosEat): FilaEat => ({
  partida_id,
  item,
  etiqueta,
  ...m,
})

// P1: consumido 4×310 + 3×310 = 2,170; EAT 2,170 + 3×310 = 3,100 < 3,200.
const p1 = fila(
  10,
  '01.01',
  "Columnas · f'c 210",
  montos([10, 3200, 7, 2170], [3, 1030, 10, 3100], false),
)
// P2: consumido 9×360 = 3,240 superó el metrado; saldo 2,800 − 3,240 = −440.
const p2 = fila(
  11,
  '01.02',
  "Vigas · f'c 280",
  montos([8, 2800, 9, 3240], [0, -440, 9, 3240], true),
)
// P3: consumido 2×310 + 1×310 = 930; EAT 930 + 2×310 = 1,550 > 1,300.
const p3 = fila(
  12,
  '02.01',
  "Losa aligerada · f'c 210",
  montos([5, 1300, 3, 930], [2, 370, 5, 1550], true),
)

// g6 sin sector: 1×310. Cargo Bombeo de F1: 150, sin volumen.
const sinSector: FilaAparteEat = {
  tipo: 'guias_sin_sector',
  concepto: 'Guías sin sector',
  ...montos([0, 0, 1, 310], [0, -310, 1, 310], false),
}
const bombeo: FilaAparteEat = {
  tipo: 'cargo',
  concepto: 'Bombeo',
  presupuesto_m3: 0,
  presupuesto_soles: 0,
  consumido_m3: null,
  consumido_soles: 150,
  saldo_m3: null,
  saldo_soles: -150,
  eat_m3: null,
  eat_soles: 150,
  sobrecosto: false,
}

const reporte: ReporteEat = {
  proyecto: { id: 1, codigo: 'OBR-01', nombre: 'Obra uno' },
  fecha_corte: '2026-10-31',
  grupos: [
    {
      grupo_id: 1,
      nombre: 'Estructuras',
      filas: [p1, p2],
      subtotal: montos([18, 6000, 16, 5410], [3, 590, 19, 6340], true),
    },
    {
      grupo_id: 2,
      nombre: 'Losas',
      filas: [p3],
      subtotal: montos([5, 1300, 3, 930], [2, 370, 5, 1550], true),
    },
  ],
  filas_aparte: [sinSector, bombeo],
  total: montos([23, 7300, 20, 6800], [5, 500, 25, 8350], true),
}

const montar = (r: ReporteEat = reporte) =>
  mount(TablaReporteEat, { props: { reporte: r }, global: { plugins: [PrimeVue] } })

// Las 8 celdas de montos, sin la del elemento.
const montosDe = (tr: Fila) =>
  tr
    .findAll('td')
    .slice(1)
    .map((td) => td.text())

describe('TablaReporteEat', () => {
  it('muestra las 9 columnas en el orden del reporte', () => {
    const encabezados = montar()
      .findAll('thead th')
      .map((th) => th.text())

    expect(encabezados).toStrictEqual([
      'Elemento',
      'Presup. m³',
      'Presup. S/',
      'Consumido m³',
      'Consumido S/',
      'Saldo m³',
      'Saldo S/',
      'EAT m³',
      'EAT S/',
    ])
  })

  it('muestra los grupos con sus elementos y subtotales, con los montos en soles', () => {
    const tabla = montar()
    const filas = tabla.findAll('[data-fila]')
    const subtotales = tabla.findAll('[data-subtotal]')

    expect(tabla.findAll('[data-grupo-nombre]').map((g) => g.text())).toStrictEqual([
      'Estructuras',
      'Losas',
    ])
    expect(filas.map((f) => f.get('td').text())).toStrictEqual([
      "Columnas · f'c 210Ítem 01.01",
      "Vigas · f'c 280Ítem 01.02",
      "Losa aligerada · f'c 210Ítem 02.01",
    ])
    expect(montosDe(filas[0]!)).toStrictEqual([
      '10.00',
      'S/ 3,200.00',
      '7.00',
      'S/ 2,170.00',
      '3.00',
      'S/ 1,030.00',
      '10.00',
      'S/ 3,100.00',
    ])
    expect(montosDe(filas[1]!)).toStrictEqual([
      '8.00',
      'S/ 2,800.00',
      '9.00',
      'S/ 3,240.00',
      '0.00',
      'S/ -440.00',
      '9.00',
      'S/ 3,240.00',
    ])
    expect(subtotales.map((s) => s.get('td').text())).toStrictEqual([
      'Subtotal Estructuras',
      'Subtotal Losas',
    ])
    expect(montosDe(subtotales[0]!)).toStrictEqual([
      '18.00',
      'S/ 6,000.00',
      '16.00',
      'S/ 5,410.00',
      '3.00',
      'S/ 590.00',
      '19.00',
      'S/ 6,340.00',
    ])
  })

  it('muestra las filas aparte y los m³ nulos de los cargos con una raya', () => {
    const aparte = montar().findAll('[data-fila-aparte]')

    expect(aparte.map((f) => f.get('td').text())).toStrictEqual(['Guías sin sector', 'Bombeo'])
    expect(montosDe(aparte[0]!)).toStrictEqual([
      '0.00',
      'S/ 0.00',
      '1.00',
      'S/ 310.00',
      '0.00',
      'S/ -310.00',
      '1.00',
      'S/ 310.00',
    ])
    expect(montosDe(aparte[1]!)).toStrictEqual([
      '0.00',
      'S/ 0.00',
      '—',
      'S/ 150.00',
      '—',
      'S/ -150.00',
      '—',
      'S/ 150.00',
    ])
  })

  it('muestra el total que cuadra con los grupos y las filas aparte', () => {
    const total = montar().get('tfoot tr')

    expect(total.get('td').text()).toBe('Total')
    expect(montosDe(total)).toStrictEqual([
      '23.00',
      'S/ 7,300.00',
      '20.00',
      'S/ 6,800.00',
      '5.00',
      'S/ 500.00',
      '25.00',
      'S/ 8,350.00',
    ])
  })

  it('resalta en rojo los elementos, subtotales y total con sobrecosto, nunca las filas aparte', () => {
    const tabla = montar()
    const enRojo = (tr: Fila) => tr.classes().includes('fila-sobrecosto')

    expect(tabla.findAll('[data-fila]').map(enRojo)).toStrictEqual([false, true, true])
    expect(tabla.findAll('[data-subtotal]').map(enRojo)).toStrictEqual([true, true])
    expect(tabla.findAll('[data-fila-aparte]').map(enRojo)).toStrictEqual([false, false])
    expect(enRojo(tabla.get('tfoot tr'))).toBe(true)
  })

  it('sin sobrecosto no resalta nada y sin filas aparte no muestra su sección', () => {
    const sinRojo = (m: MontosEat): MontosEat => ({ ...m, sobrecosto: false })
    const tabla = montar({
      ...reporte,
      grupos: [{ ...reporte.grupos[0]!, filas: [p1], subtotal: sinRojo(p1) }],
      filas_aparte: [],
      total: sinRojo(p1),
    })

    expect(tabla.findAll('.fila-sobrecosto')).toHaveLength(0)
    expect(tabla.findAll('[data-fila-aparte]')).toHaveLength(0)
    expect(tabla.text()).not.toContain('Fuera de los elementos')
  })
})
