import { http } from '@/api/http'
import { proveedoresApi } from '@/api/proveedores'
import { errorDeValidacion } from '@/services/errores'
import { resistenciasEjemplo } from '@/services/resistencias'
import { compararVolumenes, importe, sumar, volumenesCoinciden } from '@/validaciones/factura'
import type {
  FacturaDetalle,
  FacturaFila,
  LecturaFactura,
  RegistroFactura,
  RespuestaLecturaFactura,
  RespuestaGuiasFactura,
  RespuestaRegistroFactura,
} from '@/types/facturas'
import type { GuiaFila } from '@/types/guias'
import type { Proveedor } from '@/types/proveedor'

export interface FacturasServicio {
  /** Las facturas del proyecto, de la más reciente a la más antigua. */
  listar(proyectoId: number): Promise<FacturaFila[]>
  /** La factura con su detalle, sus otros cargos y las guías que cobra, con su importe. */
  obtener(id: number): Promise<FacturaDetalle>
  /** CU11 paso 1 con OCR: sube el archivo y lo lee; si el OCR falla, `lectura` es null. */
  leer(proyectoId: number, archivo: File): Promise<RespuestaLecturaFactura>
  /** CU12 paso 1: guías del proveedor y proyecto aún no facturadas. */
  guiasPorFacturar(proyectoId: number, proveedorId: number): Promise<GuiaFila[]>
  /**
   * CU11 pasos 5–6. Sin `confirmado`, si el volumen no coincide con las guías la API responde 422
   * con `requiere_confirmacion` (flujo alternativo 1).
   */
  registrar(datos: RegistroFactura): Promise<RespuestaRegistroFactura>
  /**
   * CU12 después del registro: la lista completa de guías que cobra la factura. Las que salen
   * vuelven a quedar por facturar. Si el volumen no coincide, 422 con `requiere_confirmacion`.
   */
  actualizarGuias(
    facturaId: number,
    guiaIds: number[],
    confirmado?: boolean,
  ): Promise<RespuestaGuiasFactura>
  /**
   * Elimina una factura registrada por error (p. ej. en otro proyecto): sus guías vuelven a quedar
   * por facturar y el número queda libre para registrarla bien. El motivo queda en el registro.
   */
  eliminar(facturaId: number, motivo?: string): Promise<{ message: string }>
  /** Las concreteras registradas, para elegir el proveedor de la factura. */
  proveedores(): Promise<Proveedor[]>
}

/** La imagen o el PDF de la factura; la API lo entrega con la sesión. */
export const urlArchivoFactura = (facturaId: number) =>
  `${http.defaults.baseURL}/facturas/${facturaId}/archivo`

/** Multipart con los arreglos como los lee Laravel: `lineas[0][volumen_m3]`, `guia_ids[]`. */
function formulario(datos: RegistroFactura) {
  const form = new FormData()
  const { lineas, cargos = [], guia_ids, confirmado, ...campos } = datos
  for (const [campo, valor] of Object.entries(campos)) {
    if (valor instanceof File) form.append(campo, valor, valor.name)
    else form.append(campo, String(valor))
  }
  const arreglo = (nombre: string, filas: object[]) =>
    filas.forEach((fila, i) => {
      for (const [campo, valor] of Object.entries(fila)) {
        form.append(`${nombre}[${i}][${campo}]`, String(valor))
      }
    })
  arreglo('lineas', lineas)
  arreglo('cargos', cargos)
  for (const id of guia_ids) form.append('guia_ids[]', String(id))
  // Laravel acepta «1»/«0» como booleano; «true» no.
  form.append('confirmado', confirmado ? '1' : '0')
  return form
}

export function crearFacturasApi(): FacturasServicio {
  return {
    async listar(proyectoId) {
      return (await http.get<FacturaFila[]>(`/proyectos/${proyectoId}/facturas`)).data
    },
    async obtener(id) {
      return (await http.get<FacturaDetalle>(`/facturas/${id}`)).data
    },
    async leer(proyectoId, archivo) {
      const form = new FormData()
      form.append('proyecto_id', String(proyectoId))
      form.append('archivo', archivo, archivo.name)
      return (await http.post<RespuestaLecturaFactura>('/facturas/lecturas', form)).data
    },
    async guiasPorFacturar(proyectoId, proveedorId) {
      const url = `/proyectos/${proyectoId}/guias/por-facturar`
      return (await http.get<GuiaFila[]>(url, { params: { proveedor_id: proveedorId } })).data
    },
    async registrar(datos) {
      return (await http.post<RespuestaRegistroFactura>('/facturas', formulario(datos))).data
    },
    async actualizarGuias(facturaId, guiaIds, confirmado = false) {
      const url = `/facturas/${facturaId}/guias`
      return (await http.put<RespuestaGuiasFactura>(url, { guia_ids: guiaIds, confirmado })).data
    },
    async eliminar(facturaId, motivo) {
      const datos = motivo?.trim() ? { motivo: motivo.trim() } : {}
      return (await http.delete<{ message: string }>(`/facturas/${facturaId}`, { data: datos }))
        .data
    },
    async proveedores() {
      // Las concreteras de una empresa son pocas; se traen todas para el selector.
      return (await proveedoresApi.listar({ per_page: 200, activo: 1 })).data
    },
  }
}

const proveedoresEjemplo: Proveedor[] = [
  { id: 1, ruc: '20100030595', razon_social: 'UNICON S.A.' },
  { id: 2, ruc: '20512345678', razon_social: 'Concremax S.A.' },
]

const guia = (
  id: number,
  proveedor: Proveedor,
  numero: string,
  fecha: string,
  resistenciaId: number,
  volumen_m3: number,
): GuiaFila => ({
  id,
  proyecto_id: 1,
  proveedor: { ...proveedor },
  numero,
  fecha,
  resistencia: { ...resistenciasEjemplo.find((r) => r.id === resistenciaId)! },
  codigo_producto: null,
  volumen_m3,
  placa: 'ABC-123',
  estado_asignacion: 'asignada',
  origen: 'ocr',
})

// Las guías de la factura F001-2803 del proyecto Larco: f'c 280 (id 3) y f'c 350 (id 4).
const guiasEjemplo = (): GuiaFila[] => [
  guia(1, proveedoresEjemplo[0]!, 'T001-00003855', '2026-10-27', 3, 8.5),
  guia(2, proveedoresEjemplo[0]!, 'T001-00003869', '2026-10-27', 4, 7.5),
  guia(3, proveedoresEjemplo[0]!, 'T001-00003875', '2026-10-28', 4, 7),
  guia(4, proveedoresEjemplo[1]!, 'G002-00000101', '2026-10-28', 3, 9),
]

/**
 * Para las pruebas de las vistas: valida lo mismo que el backend con datos en memoria.
 * `lectura` decide qué devuelve el OCR (null: no pudo leer).
 */
export function crearFacturasEnMemoria(
  lectura: () => LecturaFactura | null = () => null,
): FacturasServicio {
  const guias = guiasEjemplo()
  const facturas: FacturaDetalle[] = []
  /** Guía → factura que la cobra. */
  const vinculadas = new Map<number, number>()
  let siguiente = 1

  const copia = (f: FacturaDetalle): FacturaFila => {
    const { lineas: _l, cargos: _c, guias: _g, pendiente_por_resistencia: _p, ...fila } = f
    return { ...fila, ...pendiente(f), proveedor: { ...fila.proveedor } }
  }

  /** Lo mismo que calcula FacturaResource: el facturado menos el de las guías vinculadas. */
  const pendiente = (f: FacturaDetalle) => {
    const enGuias = sumar(...f.guias.map((g) => g.volumen_m3))
    return {
      volumen_guias_m3: enGuias,
      volumen_pendiente_m3: Math.max(0, sumar(f.volumen_m3, -enGuias)),
    }
  }

  const detalle = (f: FacturaDetalle): FacturaDetalle => ({
    ...structuredClone(f),
    ...pendiente(f),
    pendiente_por_resistencia: f.lineas
      .map((l) => {
        const enGuias = sumar(
          ...f.guias.filter((g) => g.resistencia.id === l.resistencia.id).map((g) => g.volumen_m3),
        )
        return {
          resistencia: { ...l.resistencia },
          facturado_m3: l.volumen_m3,
          en_guias_m3: enGuias,
          faltan_m3: Math.max(0, sumar(l.volumen_m3, -enGuias)),
        }
      })
      .filter((r) => r.faltan_m3 > 0),
  })

  return {
    async listar(proyectoId) {
      return facturas
        .filter((f) => f.proyecto_id === proyectoId)
        .sort((a, b) => b.fecha.localeCompare(a.fecha) || b.id - a.id)
        .map(copia)
    },
    async obtener(id) {
      const factura = facturas.find((f) => f.id === id)
      if (!factura) throw new Error('Factura no encontrada.')
      return detalle(factura)
    },
    async leer() {
      const leida = lectura()
      return {
        archivo_temporal: '00000000-0000-0000-0000-000000000000.pdf',
        lectura: leida,
        ...(leida ? {} : { message: 'No se pudo leer la factura' }),
      }
    },
    async guiasPorFacturar(proyectoId, proveedorId) {
      return guias
        .filter((g) => g.proyecto_id === proyectoId && g.proveedor.id === proveedorId)
        .filter((g) => !vinculadas.has(g.id))
        .map((g) => ({ ...g }))
    },
    async registrar(datos) {
      if (
        facturas.some((f) => f.proveedor.id === datos.proveedor_id && f.numero === datos.numero)
      ) {
        throw errorDeValidacion({ numero: ['La factura ya fue registrada'] })
      }
      const elegidas = datos.guia_ids.map((id) => guias.find((g) => g.id === id)!)
      const filas = datos.lineas.map((l) => ({ ...l }))
      if (!volumenesCoinciden(compararVolumenes(filas, elegidas)) && !datos.confirmado) {
        throw errorDeValidacion(
          { guia_ids: ['El volumen facturado no coincide con las guías'] },
          'requiere_confirmacion',
        )
      }
      const lineas = datos.lineas.map((l, i) => ({
        id: i + 1,
        resistencia: { ...resistenciasEjemplo.find((r) => r.id === l.resistencia_id)! },
        volumen_m3: l.volumen_m3,
        precio_unitario: l.precio_unitario,
        importe: importe(l.volumen_m3, l.precio_unitario),
      }))
      const cargos = (datos.cargos ?? []).map((c, i) => ({ id: i + 1, ...c }))
      const subtotal = sumar(...lineas.map((l) => l.importe), ...cargos.map((c) => c.importe))
      const factura: FacturaDetalle = {
        id: siguiente++,
        proyecto_id: datos.proyecto_id,
        proveedor: { ...proveedoresEjemplo.find((p) => p.id === datos.proveedor_id)! },
        numero: datos.numero.toUpperCase(),
        fecha: datos.fecha,
        volumen_m3: sumar(...lineas.map((l) => l.volumen_m3)),
        subtotal,
        igv: datos.igv,
        total: sumar(subtotal, datos.igv),
        diferencia_confirmada: !!datos.confirmado,
        origen: 'archivo_temporal' in datos ? 'ocr' : 'manual',
        guias_count: elegidas.length,
        lineas,
        cargos,
        guias: elegidas.map((g) => {
          const linea = lineas.find((l) => l.resistencia.id === g.resistencia.id)
          return { ...g, importe: linea ? importe(g.volumen_m3, linea.precio_unitario) : null }
        }),
      }
      facturas.push(factura)
      for (const g of elegidas) vinculadas.set(g.id, factura.id)
      return { data: copia(factura), message: 'Factura registrada correctamente' }
    },
    async actualizarGuias(facturaId, guiaIds, confirmado = false) {
      const factura = facturas.find((f) => f.id === facturaId)
      if (!factura) throw new Error('Factura no encontrada.')
      if (!guiaIds.length) throw errorDeValidacion({ guia_ids: ['Vincule al menos una guía'] })
      const elegidas = guiaIds.map((id) => guias.find((g) => g.id === id))
      const i = elegidas.findIndex(
        (g) =>
          !g ||
          g.proyecto_id !== factura.proyecto_id ||
          g.proveedor.id !== factura.proveedor.id ||
          (vinculadas.has(g.id) && vinculadas.get(g.id) !== facturaId),
      )
      if (i >= 0) {
        throw errorDeValidacion({
          [`guia_ids.${i}`]: ['La guía ya está facturada o no es del proveedor y proyecto'],
        })
      }
      const filas = factura.lineas.map((l) => ({
        resistencia_id: l.resistencia.id,
        volumen_m3: l.volumen_m3,
        precio_unitario: l.precio_unitario,
      }))
      const coincide = volumenesCoinciden(compararVolumenes(filas, elegidas as GuiaFila[]))
      if (!coincide && !confirmado) {
        throw errorDeValidacion(
          { guia_ids: ['El volumen facturado no coincide con las guías'] },
          'requiere_confirmacion',
        )
      }
      for (const [guiaId, id] of vinculadas) if (id === facturaId) vinculadas.delete(guiaId)
      for (const g of elegidas as GuiaFila[]) vinculadas.set(g.id, facturaId)
      factura.diferencia_confirmada = !coincide
      factura.guias_count = elegidas.length
      factura.guias = (elegidas as GuiaFila[])
        .map((g) => {
          const linea = factura.lineas.find((l) => l.resistencia.id === g.resistencia.id)
          return { ...g, importe: linea ? importe(g.volumen_m3, linea.precio_unitario) : null }
        })
        .sort((a, b) => a.fecha.localeCompare(b.fecha) || a.id - b.id)
      return {
        data: detalle(factura),
        message: 'Guías de la factura actualizadas correctamente',
      }
    },
    async eliminar(facturaId, motivo) {
      const i = facturas.findIndex((f) => f.id === facturaId)
      if (i < 0) throw new Error('Factura no encontrada.')
      if ((motivo?.trim().length ?? 0) > 500) {
        throw errorDeValidacion({ motivo: ['Debe tener como máximo 500 caracteres'] })
      }
      facturas.splice(i, 1)
      for (const [guiaId, id] of vinculadas) if (id === facturaId) vinculadas.delete(guiaId)
      return { message: 'Factura eliminada correctamente' }
    },
    async proveedores() {
      return proveedoresEjemplo.map((p) => ({ ...p }))
    },
  }
}

export const facturasServicio: FacturasServicio = crearFacturasApi()
