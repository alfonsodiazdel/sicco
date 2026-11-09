import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { AxiosError, AxiosHeaders, type AxiosAdapter, type InternalAxiosRequestConfig } from 'axios'
import { http, mensajeDeError } from '@/api/http'
import { documentosServicio } from '@/services/documentos'

let pedidos: InternalAxiosRequestConfig[] = []
const adapterOriginal = http.defaults.adapter

function responder(data: unknown, headers: Record<string, string> = {}): AxiosAdapter {
  return async (config) => {
    pedidos.push(config)
    return { data, status: 200, statusText: 'OK', headers: new AxiosHeaders(headers), config }
  }
}

function rechazar(status: number, cuerpo: object): AxiosAdapter {
  return async (config) => {
    pedidos.push(config)
    const data = new Blob([JSON.stringify(cuerpo)], { type: 'application/json' })
    throw new AxiosError('Request failed', AxiosError.ERR_BAD_REQUEST, config, undefined, {
      data,
      status,
      statusText: '',
      headers: new AxiosHeaders({ 'content-type': 'application/json' }),
      config,
    })
  }
}

const url = (config: InternalAxiosRequestConfig) => decodeURIComponent(http.getUri(config))

beforeEach(() => {
  pedidos = []
})
afterEach(() => {
  http.defaults.adapter = adapterOriginal
})

describe('documentosServicio', () => {
  it('lista las guías con los filtros que tienen valor', async () => {
    http.defaults.adapter = responder({ data: [{ id: 1, numero: 'T001-1' }] })

    const guias = await documentosServicio.listarGuias(7, {
      desde: '2026-11-01',
      hasta: null,
      proveedor_id: 3,
      estado: 'sin_sector',
    })

    expect(guias).toEqual([{ id: 1, numero: 'T001-1' }])
    expect(url(pedidos[0]!)).toBe(
      '/api/v1/proyectos/7/documentos/guias?desde=2026-11-01&proveedor_id=3&estado=sin_sector',
    )
  })

  it('lista las facturas sin parámetros cuando no hay filtros', async () => {
    http.defaults.adapter = responder({ data: [] })

    await documentosServicio.listarFacturas(7, { desde: null, hasta: null, proveedor_id: null })

    expect(url(pedidos[0]!)).toBe('/api/v1/proyectos/7/documentos/facturas')
  })

  it('descarga la selección con ids[] y toma el nombre de Content-Disposition', async () => {
    const zip = new Blob(['PK'], { type: 'application/zip' })
    http.defaults.adapter = responder(zip, {
      'content-disposition': 'attachment; filename=guias-PRY-001-seleccion.zip',
    })

    const archivo = await documentosServicio.descargarZip(7, 'guias', { ids: [4, 9] })

    expect(url(pedidos[0]!)).toBe('/api/v1/proyectos/7/documentos/guias/zip?ids[]=4&ids[]=9')
    expect(pedidos[0]!.responseType).toBe('blob')
    expect(archivo).toEqual({ blob: zip, nombre: 'guias-PRY-001-seleccion.zip' })
  })

  it('descarga lo filtrado y acepta el nombre entre comillas o codificado', async () => {
    http.defaults.adapter = responder(new Blob(['PK']), {
      'content-disposition': `attachment; filename="facturas-PRY-001-todo.zip"; filename*=utf-8''facturas-PRY-001-2026-11-01_fin.zip`,
    })

    const archivo = await documentosServicio.descargarZip(7, 'facturas', {
      desde: '2026-11-01',
      hasta: null,
      proveedor_id: null,
    })

    expect(url(pedidos[0]!)).toBe('/api/v1/proyectos/7/documentos/facturas/zip?desde=2026-11-01')
    expect(archivo.nombre).toBe('facturas-PRY-001-2026-11-01_fin.zip')
  })

  it('sin Content-Disposition usa un nombre por defecto', async () => {
    http.defaults.adapter = responder(new Blob(['PK']))

    const archivo = await documentosServicio.descargarZip(7, 'facturas', { ids: [1] })

    expect(archivo.nombre).toBe('facturas.zip')
  })

  it('un 422 llega como Blob y se muestra el mensaje en español', async () => {
    http.defaults.adapter = rechazar(422, {
      message: 'No hay documentos que coincidan con la búsqueda.',
      code: 'sin_documentos',
    })

    const error = await documentosServicio.descargarZip(7, 'guias', { ids: [1] }).catch((e) => e)

    expect(mensajeDeError(error)).toBe('No hay documentos que coincidan con la búsqueda.')
  })

  it('en un 422 de validación muestra el primer error', async () => {
    http.defaults.adapter = rechazar(422, {
      message: 'Ingrese una fecha válida (and 1 more error)',
      errors: { desde: ['Ingrese una fecha válida'], hasta: ['Ingrese una fecha válida'] },
    })

    const error = await documentosServicio
      .descargarZip(7, 'guias', { desde: 'x', hasta: null, proveedor_id: null })
      .catch((e) => e)

    expect(mensajeDeError(error)).toBe('Ingrese una fecha válida')
  })
})
