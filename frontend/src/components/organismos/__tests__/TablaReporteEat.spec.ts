import { describe, expect, it } from 'vitest'
import { mount, type DOMWrapper } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import TablaReporteEat from '@/components/organismos/TablaReporteEat.vue'
import type { FilaAparteEat, FilaEat, MontosEat, ReporteEat } from '@/types/reportes'

type Doce = [
  number,
  number,
  number,
  number,
  number,
  number,
  number,
  number,
  number,
  number,
  number,
  number,
]
type Fila = Omit<DOMWrapper<Element>, 'exists'>

// Escenario de la prueba del backend (corte 2026-10-31, desperdicio 10 %, S2 culminado), en el orden de
// MontosEat: presup. m³ y S/, consumido m³ y S/, saldo S/, real m³ y S/, proyectado m³ y S/, EAT m³ y
// S/, variación S/.
const montos = (m: Doce, sobrecosto: boolean): MontosEat => {
  const [
    presupuesto_m3,
    presupuesto_soles,
    consumido_m3,
    consumido_soles,
    saldo_soles,
    real_m3,
    real_soles,
    proyectado_m3,
    proyectado_soles,
    eat_m3,
    eat_soles,
    variacion_soles,
  ] = m
  return {
    presupuesto_m3,
    presupuesto_soles,
    consumido_m3,
    consumido_soles,
    saldo_soles,
    real_m3,
    real_soles,
    proyectado_m3,
    proyectado_soles,
    eat_m3,
    eat_soles,
    variacion_soles,
    sobrecosto,
  }
}

// El PU proyectado, de dónde salió y el del presupuesto: solo en las partidas.
const fila = (
  partida_id: number,
  item: string,
  etiqueta: string,
  m: MontosEat,
  pu: Pick<FilaEat, 'pu_proyectado' | 'pu_origen' | 'pu_presupuesto'>,
): FilaEat => ({
  partida_id,
  item,
  etiqueta,
  ...m,
  ...pu,
})

// P1: S1 abierto. Presup. 11 × 320 = 3,520; proyecta 11 × 310 = 3,410; variación 110.
const p1 = fila(
  10,
  '01.01',
  "Columnas · f'c 210",
  montos([11, 3520, 7, 2170, 1350, 0, 0, 11, 3410, 11, 3410, 110], false),
  { pu_proyectado: 310, pu_origen: 'facturado', pu_presupuesto: 320 },
)
// P2: S2 culminado con 9 × 360 = 3,240 real; variación 3,080 − 3,240 = −160. El último facturado de
// 280 (360) pasa al del presupuesto (350).
const p2 = fila(
  11,
  '01.02',
  "Vigas · f'c 280",
  montos([8.8, 3080, 9, 3240, -160, 9, 3240, 0, 0, 9, 3240, -160], true),
  { pu_proyectado: 360, pu_origen: 'facturado', pu_presupuesto: 350 },
)
// P3: S3 abierto. Proyecta 5.5 × 310 = 1,705 > 1,430.
const p3 = fila(
  12,
  '02.01',
  "Losa aligerada · f'c 210",
  montos([5.5, 1430, 3, 930, 500, 0, 0, 5.5, 1705, 5.5, 1705, -275], true),
  { pu_proyectado: 310, pu_origen: 'facturado', pu_presupuesto: 260 },
)

const aviso = 'Asigne un sector a estas guías: mientras no lo tengan, no suman al total.'

// Guía sin sector: 1 × 310, solo lo recibido; no suma. Cargo Bombeo de F1: 150, sin volumen ni proyección.
const sinSector: FilaAparteEat = {
  tipo: 'guias_sin_sector',
  concepto: 'Guías sin sector',
  aviso,
  presupuesto_m3: 0,
  presupuesto_soles: 0,
  consumido_m3: 1,
  consumido_soles: 310,
  saldo_soles: null,
  real_m3: null,
  real_soles: null,
  proyectado_m3: null,
  proyectado_soles: null,
  eat_m3: null,
  eat_soles: null,
  variacion_soles: null,
  sobrecosto: false,
}
const bombeo: FilaAparteEat = {
  tipo: 'cargo',
  concepto: 'Bombeo',
  presupuesto_m3: 0,
  presupuesto_soles: 0,
  consumido_m3: null,
  consumido_soles: 150,
  saldo_soles: -150,
  real_m3: null,
  real_soles: 150,
  proyectado_m3: null,
  proyectado_soles: 0,
  eat_m3: null,
  eat_soles: 150,
  variacion_soles: -150,
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
      subtotal: montos([19.8, 6600, 16, 5410, 1190, 9, 3240, 11, 3410, 20, 6650, -50], true),
    },
    {
      grupo_id: 2,
      nombre: 'Losas',
      filas: [p3],
      subtotal: montos([5.5, 1430, 3, 930, 500, 0, 0, 5.5, 1705, 5.5, 1705, -275], true),
    },
  ],
  filas_aparte: [sinSector, bombeo],
  // Grupos + Bombeo, sin la guía sin sector: EAT 6,650 + 1,705 + 150 = 8,505.
  total: montos([25.3, 8030, 19, 6490, 1540, 9, 3390, 16.5, 5115, 25.5, 8505, -475], true),
}

const montar = (r: ReporteEat = reporte) =>
  mount(TablaReporteEat, { props: { reporte: r }, global: { plugins: [PrimeVue] } })

// Las 11 celdas de montos, sin la del elemento.
const montosDe = (tr: Fila) =>
  tr
    .findAll('td')
    .slice(1)
    .map((td) => td.text())

describe('TablaReporteEat', () => {
  it('muestra las 12 columnas en el orden del reporte, iguales al Excel y al PDF', () => {
    const encabezados = montar()
      .findAll('thead th')
      .map((th) => th.text())

    expect(encabezados).toStrictEqual([
      'Elemento',
      'Presup. m³',
      'Presup. S/',
      'Consumido m³',
      'Consumido S/',
      'Saldo S/',
      'Real culminado S/',
      'Proyectado m³',
      'PU proyectado S/',
      'Proyectado S/',
      'EAT S/',
      'Variación S/',
    ])
  })

  it('cada encabezado tiene su ayuda, accesible también con el teclado', () => {
    const ayudas = montar().findAll('thead [data-ayuda]')

    expect(ayudas).toHaveLength(12)
    for (const ayuda of ayudas) {
      expect(ayuda.element.tagName).toBe('ABBR')
      expect(ayuda.attributes('tabindex')).toBe('0')
      expect(ayuda.attributes('aria-label')).toMatch(new RegExp(`^${ayuda.text()}: .+`))
    }
    const etiqueta = (texto: string) =>
      ayudas.find((a) => a.text() === texto)!.attributes('aria-label')
    expect(etiqueta('EAT S/')).toBe(
      'EAT S/: Costo estimado al término de la obra: real culminado + proyectado.',
    )
    expect(etiqueta('Proyectado S/')).toContain('precio vigente')
    expect(etiqueta('Variación S/')).toContain('Negativa (en rojo) es sobrecosto')
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
      '11.00',
      'S/ 3,520.00',
      '7.00',
      'S/ 2,170.00',
      'S/ 1,350.00',
      'S/ 0.00',
      '11.00',
      'S/ 310.00',
      'S/ 3,410.00',
      'S/ 3,410.00',
      'S/ 110.00',
    ])
    expect(montosDe(filas[1]!)).toStrictEqual([
      '8.80',
      'S/ 3,080.00',
      '9.00',
      'S/ 3,240.00',
      'S/ -160.00',
      'S/ 3,240.00',
      '0.00',
      'S/ 360.00▲',
      'S/ 0.00',
      'S/ 3,240.00',
      'S/ -160.00',
    ])
    expect(subtotales.map((s) => s.get('td').text())).toStrictEqual([
      'Subtotal Estructuras',
      'Subtotal Losas',
    ])
    expect(montosDe(subtotales[0]!)).toStrictEqual([
      '19.80',
      'S/ 6,600.00',
      '16.00',
      'S/ 5,410.00',
      'S/ 1,190.00',
      'S/ 3,240.00',
      '11.00',
      '—',
      'S/ 3,410.00',
      'S/ 6,650.00',
      'S/ -50.00',
    ])
  })

  it('muestra las filas aparte con una raya en los montos que no aplican', () => {
    const aparte = montar().findAll('[data-fila-aparte]')

    expect(aparte.map((f) => f.get('td').text())).toStrictEqual([
      `Guías sin sector${aviso}`,
      'Bombeo',
    ])
    // La guía sin sector no tiene EAT: solo lo recibido.
    expect(montosDe(aparte[0]!)).toStrictEqual([
      '0.00',
      'S/ 0.00',
      '1.00',
      'S/ 310.00',
      '—',
      '—',
      '—',
      '—',
      '—',
      '—',
      '—',
    ])
    // El cargo no tiene volumen ni proyección: su EAT es lo facturado.
    expect(montosDe(aparte[1]!)).toStrictEqual([
      '0.00',
      'S/ 0.00',
      '—',
      'S/ 150.00',
      'S/ -150.00',
      'S/ 150.00',
      '—',
      '—',
      'S/ 0.00',
      'S/ 150.00',
      'S/ -150.00',
    ])
  })

  it('la fila de guías sin sector muestra sus m³, sus S/ y el aviso de asignarles sector', () => {
    const fila = montar().findAll('[data-fila-aparte]')[0]!

    expect(fila.get('[data-aviso]').text()).toBe(aviso)
    expect(fila.get('[data-aviso] i').classes()).toEqual(
      expect.arrayContaining(['pi', 'pi-exclamation-triangle']),
    )
    expect(fila.findAll('td')).toHaveLength(12)
    expect(montosDe(fila).slice(2, 4)).toStrictEqual(['1.00', 'S/ 310.00'])
  })

  it('un cargo no muestra aviso', () => {
    const cargo = montar().findAll('[data-fila-aparte]')[1]!

    expect(cargo.get('td').text()).toBe('Bombeo')
    expect(cargo.find('[data-aviso]').exists()).toBe(false)
  })

  it('muestra el total que llega de la API: los grupos y los cargos, sin las guías sin sector', () => {
    const total = montar().get('tfoot tr')

    expect(total.get('td').text()).toBe('Total')
    expect(montosDe(total)).toStrictEqual([
      '25.30',
      'S/ 8,030.00',
      '19.00',
      'S/ 6,490.00',
      'S/ 1,540.00',
      'S/ 3,390.00',
      '16.50',
      '—',
      'S/ 5,115.00',
      'S/ 8,505.00',
      'S/ -475.00',
    ])
  })

  it('muestra el PU proyectado de cada partida con su origen, y ▲ cuando sube sobre el del presupuesto', () => {
    const pus = montar().findAll('[data-fila] [data-pu]')

    expect(pus.map((pu) => pu.text())).toStrictEqual(['S/ 310.00', 'S/ 360.00▲', 'S/ 310.00▲'])
    expect(pus.map((pu) => pu.find('[data-pu-sube]').exists())).toStrictEqual([false, true, true])
    expect(pus[0]!.attributes('tabindex')).toBe('0')
    expect(pus[0]!.attributes('aria-label')).toBe(
      "S/ 310.00. Último precio facturado de su f'c hasta el corte. Presupuesto: S/ 320.00.",
    )
    expect(pus[1]!.attributes('aria-label')).toBe(
      "S/ 360.00. Último precio facturado de su f'c hasta el corte. Sobre el del presupuesto, S/ 350.00.",
    )
  })

  it("sin facturas de su f'c el PU proyectado es el de la partida, y lo dice", () => {
    const delPresupuesto: FilaEat = {
      ...p1,
      pu_proyectado: 320,
      pu_origen: 'presupuesto',
      pu_presupuesto: 320,
    }
    const tabla = montar({
      ...reporte,
      grupos: [{ ...reporte.grupos[0]!, filas: [delPresupuesto] }],
    })
    const pu = tabla.get('[data-fila] [data-pu]')

    expect(pu.text()).toBe('S/ 320.00')
    expect(pu.attributes('aria-label')).toBe(
      "S/ 320.00. Precio de la partida: aún no hay facturas de su f'c hasta el corte. Presupuesto: S/ 320.00.",
    )
  })

  it('el PU no se suma: subtotales, total y filas aparte muestran una raya', () => {
    const tabla = montar()
    const columnaPu = (tr: Fila) => montosDe(tr)[7]

    expect(tabla.findAll('[data-subtotal]').map(columnaPu)).toStrictEqual(['—', '—'])
    expect(tabla.findAll('[data-fila-aparte]').map(columnaPu)).toStrictEqual(['—', '—'])
    expect(columnaPu(tabla.get('tfoot tr'))).toBe('—')
    expect(tabla.findAll('[data-subtotal] [data-pu], tfoot [data-pu]')).toHaveLength(0)
  })

  it('un saldo o una variación en cero negativo se muestran sin signo', () => {
    const enCero = montos([10, 3100, 10, 3100, -0, 10, 3100, 0, 0, 10, 3100, -0], false)
    const tabla = montar({
      ...reporte,
      grupos: [{ ...reporte.grupos[0]!, filas: [{ ...p1, ...enCero }], subtotal: enCero }],
      filas_aparte: [],
      total: enCero,
    })

    expect(montosDe(tabla.get('[data-fila]'))[4]).toBe('S/ 0.00')
    expect(montosDe(tabla.get('[data-fila]'))[10]).toBe('S/ 0.00')
    expect(montosDe(tabla.get('tfoot tr'))[10]).toBe('S/ 0.00')
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
