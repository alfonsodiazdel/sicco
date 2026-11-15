import { describe, expect, it } from 'vitest'
import {
  avisoPendiente,
  compararVolumenes,
  igvSugerido,
  importe,
  m3Aviso,
  subtotalFactura,
  subtotalLineas,
  sugerirGuias,
  validarFactura,
  volumenesCoinciden,
  type ValoresFactura,
} from '@/validaciones/factura'

const pdf = () => new File(['%PDF'], 'factura.pdf', { type: 'application/pdf' })

const valores = (cambios: Partial<ValoresFactura> = {}): ValoresFactura => ({
  numero: 'F001-00002803',
  fecha: '2026-10-27',
  proveedor_id: 1,
  archivo: pdf(),
  lineas: [{ resistencia_id: 3, volumen_m3: 8.5, precio_unitario: 291 }],
  igv: 445.23,
  guia_ids: [1],
  ...cambios,
})

describe('importes de la factura', () => {
  it('redondea volumen × precio al céntimo como el backend', () => {
    // 8.33 × 291.35 = 2426.9455; en coma flotante da 2426.9454999…
    expect(importe(8.33, 291.35)).toBe(2426.95)
    expect(importe(14.5, 329)).toBe(4770.5)
  })

  it('suma solo las líneas completas y propone el IGV al 18 %', () => {
    const subtotal = subtotalLineas([
      { resistencia_id: 3, volumen_m3: 8.5, precio_unitario: 291 },
      { resistencia_id: 4, volumen_m3: 14.5, precio_unitario: 329 },
      { resistencia_id: null, volumen_m3: 3, precio_unitario: null },
    ])
    expect(subtotal).toBe(7244)
    expect(igvSugerido(subtotal)).toBe(1303.92)
  })
})

describe('comparación de volúmenes (CU11 paso 5)', () => {
  const guia = (resistencia: number, volumen_m3: number) => ({
    resistencia: { id: resistencia },
    volumen_m3,
  })
  const lineas = [
    { resistencia_id: 3, volumen_m3: 8.5, precio_unitario: 291 },
    { resistencia_id: 4, volumen_m3: 14.5, precio_unitario: 329 },
  ]

  it('coincide cuando cada resistencia suma lo facturado', () => {
    const c = compararVolumenes(lineas, [guia(3, 8.5), guia(4, 7.5), guia(4, 7)])
    expect(volumenesCoinciden(c)).toBe(true)
  })

  it('no coincide si el total es igual pero las resistencias no', () => {
    const c = compararVolumenes(lineas, [guia(3, 8.5), guia(3, 14.5)])
    expect(volumenesCoinciden(c)).toBe(false)
    expect(c).toContainEqual({ resistencia_id: 4, facturado: 14.5, guias: 0 })
    expect(c).toContainEqual({ resistencia_id: 3, facturado: 8.5, guias: 23 })
  })

  it('suma sin errores de coma flotante', () => {
    const c = compararVolumenes(
      [{ resistencia_id: 3, volumen_m3: 0.3, precio_unitario: 1 }],
      [guia(3, 0.1), guia(3, 0.2)],
    )
    expect(volumenesCoinciden(c)).toBe(true)
  })
})

describe('validarFactura', () => {
  it('acepta una factura completa', () => {
    expect(validarFactura(valores())).toEqual({})
  })

  it('exige los datos, el archivo, una resistencia y una guía', () => {
    expect(
      validarFactura(
        valores({ numero: ' ', proveedor_id: null, archivo: null, lineas: [], guia_ids: [] }),
      ),
    ).toEqual({
      numero: 'Este dato es requerido',
      proveedor_id: 'Este dato es requerido',
      archivo: 'Este dato es requerido',
      lineas: 'Agregue al menos una resistencia',
      guia_ids: 'Vincule al menos una guía',
    })
  })

  it('rechaza un archivo que no es imagen ni PDF y resistencias repetidas', () => {
    const errores = validarFactura(
      valores({
        archivo: new File(['x'], 'factura.docx', { type: 'application/msword' }),
        lineas: [
          { resistencia_id: 3, volumen_m3: 0, precio_unitario: 291 },
          { resistencia_id: 3, volumen_m3: 2, precio_unitario: null },
        ],
      }),
    )
    expect(errores).toMatchObject({
      archivo: 'Adjunte una imagen (JPG, PNG o WebP) o un PDF de hasta 10 MB',
      'lineas.0.volumen_m3': 'Ingrese un valor mayor a cero',
      'lineas.1.precio_unitario': 'Este dato es requerido',
    })
  })

  it('marca la resistencia repetida en su fila', () => {
    const errores = validarFactura(
      valores({
        lineas: [
          { resistencia_id: 3, volumen_m3: 1, precio_unitario: 291 },
          { resistencia_id: 3, volumen_m3: 2, precio_unitario: 291 },
        ],
      }),
    )
    expect(errores).toEqual({ 'lineas.1.resistencia_id': 'La resistencia ya está en la factura' })
  })
})

describe('otros cargos y archivo leído', () => {
  it('suma los otros cargos al subtotal', () => {
    expect(
      subtotalFactura(
        [{ resistencia_id: 3, volumen_m3: 14.5, precio_unitario: 295 }],
        [{ descripcion: 'Bombeo', importe: 800 }],
      ),
    ).toBe(5077.5)
  })

  it('acepta la factura con el archivo ya subido al leerla', () => {
    expect(validarFactura(valores({ archivo: null, archivo_temporal: 'x.pdf' }))).toEqual({})
  })

  it('valida los otros cargos', () => {
    expect(validarFactura(valores({ cargos: [{ descripcion: ' ', importe: 0 }] }))).toEqual({
      'cargos.0.descripcion': 'Este dato es requerido',
      'cargos.0.importe': 'Ingrese un valor mayor a cero',
    })
  })
})

describe('sugerirGuias', () => {
  const guia = (id: number, fecha: string, resistencia: number, volumen_m3: number) => ({
    id,
    fecha,
    resistencia: { id: resistencia },
    volumen_m3,
  })
  const pendientes = [
    guia(4, '2026-10-30', 3, 8),
    guia(1, '2026-10-27', 3, 8.5),
    guia(2, '2026-10-27', 4, 7.5),
    guia(3, '2026-10-28', 4, 7),
  ]

  it('toma por resistencia las más antiguas hasta completar el volumen', () => {
    expect(
      sugerirGuias(pendientes, [
        { resistencia_id: 3, volumen_m3: 8.5, precio_unitario: 291 },
        { resistencia_id: 4, volumen_m3: 14.5, precio_unitario: 329 },
      ]),
    ).toEqual([1, 2, 3])
  })

  it('no propone una resistencia que no cuadra exacto', () => {
    expect(
      sugerirGuias(pendientes, [
        { resistencia_id: 3, volumen_m3: 10, precio_unitario: 291 },
        { resistencia_id: 4, volumen_m3: 7.5, precio_unitario: 329 },
      ]),
    ).toEqual([2])
  })
})

describe('aviso de m³ por vincular', () => {
  it('m3Aviso muestra los enteros sin decimales y el resto con dos', () => {
    expect(m3Aviso(20)).toBe('20 m³')
    expect(m3Aviso(14.5)).toBe('14.50 m³')
    expect(m3Aviso(0.5)).toBe('0.50 m³')
  })

  it('26 m³ facturados y 6 m³ en guías faltan 20 m³ sin decimales', () => {
    expect(avisoPendiente({ volumen_m3: 26, volumen_guias_m3: 6, volumen_pendiente_m3: 20 })).toBe(
      'Faltan 20 m³ por vincular (facturado 26 m³, en guías 6 m³)',
    )
  })
})
