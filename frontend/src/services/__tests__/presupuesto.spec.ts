import { describe, expect, it } from 'vitest'
import { soles, totalPartida, totalPresupuesto } from '@/services/presupuesto'

const partida = (metrado_m3: number, factor_desperdicio: number, precio_unitario: number) => ({
  metrado_m3,
  factor_desperdicio,
  precio_unitario,
})

describe('presupuesto', () => {
  it('calcula el total de la partida como metrado × (1 + % desperdicio) × precio unitario', () => {
    expect(totalPartida(partida(320, 5, 385))).toBe(129360)
    expect(totalPartida(partida(180.5, 3, 420))).toBe(78084.3)
  })

  it('con 0 % de desperdicio el total es metrado × precio unitario', () => {
    expect(totalPartida(partida(10, 0, 400))).toBe(4000)
  })

  it('redondea el total a dos decimales', () => {
    // 12.5 × 1.05 × 33.33 = 437.45625
    expect(totalPartida(partida(12.5, 5, 33.33))).toBe(437.46)
    // 0.5 × 2.01 da 1.00499… en coma flotante: sin corrección quedaría en 1.00.
    expect(totalPartida(partida(0.5, 0, 2.01))).toBe(1.01)
  })

  it('el total presupuestado es la suma de los totales de las partidas', () => {
    expect(
      totalPresupuesto([partida(320, 5, 385), partida(180.5, 3, 420), partida(245, 5, 390)]),
    ).toBe(307771.8)
    expect(totalPresupuesto([partida(12.5, 5, 33.33), partida(0.5, 0, 2.01)])).toBe(438.47)
    expect(totalPresupuesto([])).toBe(0)
  })

  it('muestra los importes en soles con dos decimales', () => {
    expect(soles(307771.8)).toBe('S/ 307,771.80')
    expect(soles(0)).toBe('S/ 0.00')
  })
})
