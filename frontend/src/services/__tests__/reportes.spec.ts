import { afterEach, describe, expect, it, vi } from 'vitest'
import { AxiosError, type AxiosAdapter, type AxiosResponse } from 'axios'
import { erroresDeValidacion, http, mensajeDeError } from '@/api/http'
import { crearReportesApi } from '@/services/reportes'
import type { ReporteEat } from '@/types/reportes'

const montos = {
  presupuesto_m3: 11,
  presupuesto_soles: 3520,
  consumido_m3: 7,
  consumido_soles: 2170,
  saldo_soles: 1350,
  real_m3: 0,
  real_soles: 0,
  proyectado_m3: 11,
  proyectado_soles: 3410,
  eat_m3: 11,
  eat_soles: 3410,
  variacion_soles: 110,
  sobrecosto: false,
}

const reporte: ReporteEat = {
  proyecto: { id: 1, codigo: 'OBR-01', nombre: 'Obra uno' },
  fecha_corte: '2026-10-31',
  grupos: [
    {
      grupo_id: 1,
      nombre: 'Estructuras',
      filas: [{ partida_id: 10, item: '01.01', etiqueta: "Columnas · f'c 210", ...montos }],
      subtotal: montos,
    },
  ],
  filas_aparte: [],
  total: montos,
}

const respuesta = <T>(data: T) => Promise.resolve({ data })

const adapterOriginal = http.defaults.adapter

afterEach(() => {
  vi.restoreAllMocks()
  http.defaults.adapter = adapterOriginal
})

describe('reportes por la API (CU15)', () => {
  it('eat pide el reporte con la fecha de corte y lo devuelve sin mensaje', async () => {
    const get = vi.spyOn(http, 'get').mockReturnValue(respuesta({ data: reporte }))

    expect(await crearReportesApi().eat(1, '2026-10-31')).toStrictEqual({
      reporte,
      mensaje: null,
    })
    expect(get).toHaveBeenCalledWith('/proyectos/1/reportes/eat', {
      params: { fecha_corte: '2026-10-31' },
    })
  })

  it('sin datos devuelve el reporte nulo con el mensaje del servidor', async () => {
    const mensaje = 'No hay información para la fecha seleccionada'
    vi.spyOn(http, 'get').mockReturnValue(respuesta({ data: null, mensaje }))

    expect(await crearReportesApi().eat(1, '2026-10-03')).toStrictEqual({
      reporte: null,
      mensaje,
    })
  })

  it('descargarEat pide el Excel y el PDF como blob', async () => {
    const archivo = new Blob(['PK'])
    const get = vi.spyOn(http, 'get').mockReturnValue(respuesta(archivo))
    const api = crearReportesApi()

    expect(await api.descargarEat(1, '2026-10-31', 'excel')).toBe(archivo)
    expect(await api.descargarEat(1, '2026-10-31', 'pdf')).toBe(archivo)
    expect(get.mock.calls).toStrictEqual([
      [
        '/proyectos/1/reportes/eat/excel',
        { params: { fecha_corte: '2026-10-31' }, responseType: 'blob' },
      ],
      [
        '/proyectos/1/reportes/eat/pdf',
        { params: { fecha_corte: '2026-10-31' }, responseType: 'blob' },
      ],
    ])
  })

  // Con responseType blob, axios entrega también el cuerpo del error como Blob; el adapter falso
  // hace que el error pase por los interceptores de http.
  const rechazarEnBlob = (cuerpo: object) => {
    const adapter: AxiosAdapter = (config) =>
      Promise.reject(
        new AxiosError('422', AxiosError.ERR_BAD_REQUEST, config, undefined, {
          status: 422,
          config,
          data: new Blob([JSON.stringify(cuerpo)], { type: 'application/json' }),
        } as AxiosResponse),
      )
    http.defaults.adapter = adapter
  }

  it('un 422 al descargar llega con su mensaje legible', async () => {
    const mensaje = 'No hay información para la fecha seleccionada'
    rechazarEnBlob({ message: mensaje })

    const error = await crearReportesApi()
      .descargarEat(1, '2026-10-03', 'pdf')
      .catch((e: unknown) => e)

    expect(error).toBeInstanceOf(AxiosError)
    expect(mensajeDeError(error)).toBe(mensaje)
  })

  it('un 422 de validación al descargar conserva el error de la fecha de corte', async () => {
    const fecha = 'La fecha de corte no puede ser posterior a hoy.'
    rechazarEnBlob({ message: fecha, errors: { fecha_corte: [fecha] } })

    const error = await crearReportesApi()
      .descargarEat(1, '2026-11-10', 'excel')
      .catch((e: unknown) => e)

    expect(erroresDeValidacion(error)).toEqual({ fecha_corte: fecha })
  })

  it('un error sin cuerpo JSON pasa tal cual', async () => {
    const error = new AxiosError('Network Error', AxiosError.ERR_NETWORK)
    vi.spyOn(http, 'get').mockRejectedValue(error)

    await expect(crearReportesApi().descargarEat(1, '2026-10-31', 'pdf')).rejects.toBe(error)
  })
})
