import { http } from '@/api/http'
import { proveedoresApi } from '@/api/proveedores'
import { errorDeValidacion } from '@/services/errores'
import { resistenciasEjemplo } from '@/services/resistencias'
import type { GuiaFila, RegistroGuia, RespuestaLectura } from '@/types/guias'
import type { Proveedor } from '@/types/proveedor'

export interface GuiasServicio {
  /** CU06 pasos 2–4 / CU07: sube la foto y la lee con OCR. */
  leer(proyectoId: number, foto: Blob): Promise<RespuestaLectura>
  /** CU06 paso 5 y CU08. */
  registrar(datos: RegistroGuia): Promise<GuiaFila>
  /** Las concreteras registradas, para elegir el proveedor de la guía. */
  proveedores(): Promise<Proveedor[]>
}

const NOMBRE_FOTO = 'guia.jpg'

function formulario(datos: object) {
  const form = new FormData()
  for (const [campo, valor] of Object.entries(datos)) {
    if (valor === undefined || valor === null) continue
    if (valor instanceof Blob) form.append(campo, valor, NOMBRE_FOTO)
    else form.append(campo, String(valor))
  }
  return form
}

export function crearGuiasApi(): GuiasServicio {
  return {
    async leer(proyectoId, foto) {
      const form = formulario({ proyecto_id: proyectoId, foto })
      return (await http.post<RespuestaLectura>('/guias/lecturas', form)).data
    },
    async registrar(datos) {
      return (await http.post<GuiaFila>('/guias', formulario(datos))).data
    },
    async proveedores() {
      // Las concreteras de una empresa son pocas; se traen todas para el selector.
      return (await proveedoresApi.listar({ per_page: 200 })).data
    },
  }
}

const proveedoresEjemplo: Proveedor[] = [
  { id: 1, ruc: '20100030595', razon_social: 'UNICON S.A.' },
  { id: 2, ruc: '20512345678', razon_social: 'Concremax S.A.' },
]

/** Para las pruebas de la vista: `lectura` decide qué devuelve el OCR. */
export function crearGuiasEnMemoria(
  lectura: () => RespuestaLectura['lectura'] = () => null,
): GuiasServicio {
  const guias: GuiaFila[] = []
  const proveedores = proveedoresEjemplo.map((p) => ({ ...p }))
  let siguiente = 1

  return {
    async leer() {
      const leida = lectura()
      return {
        foto_temporal: '00000000-0000-0000-0000-000000000000.jpg',
        lectura: leida,
        ...(leida ? {} : { message: 'No se pudo leer la guía' }),
      }
    },
    async registrar(datos) {
      let proveedor =
        'proveedor_id' in datos
          ? proveedores.find((p) => p.id === datos.proveedor_id)
          : proveedores.find((p) => p.ruc === datos.proveedor_ruc)
      if (!proveedor && 'proveedor_ruc' in datos) {
        proveedor = {
          id: proveedores.length + 1,
          ruc: datos.proveedor_ruc,
          razon_social: datos.proveedor_razon_social,
        }
        proveedores.push(proveedor)
      }
      if (!proveedor) throw errorDeValidacion({ proveedor_id: ['Este dato es requerido'] })
      if (guias.some((g) => g.proveedor.id === proveedor.id && g.numero === datos.numero)) {
        throw errorDeValidacion({ numero: ['La guía ya fue registrada'] })
      }
      const resistencia = resistenciasEjemplo.find((r) => r.id === datos.resistencia_id)
      if (!resistencia) throw errorDeValidacion({ resistencia_id: ['Este dato es requerido'] })
      const guia: GuiaFila = {
        id: siguiente++,
        proyecto_id: datos.proyecto_id,
        proveedor: { ...proveedor },
        numero: datos.numero,
        fecha: datos.fecha,
        resistencia: { ...resistencia },
        codigo_producto: datos.codigo_producto || null,
        volumen_m3: datos.volumen_m3,
        placa: datos.placa || null,
        estado_asignacion: 'sin_sector',
        origen: 'foto_temporal' in datos ? 'ocr' : 'manual',
      }
      guias.push(guia)
      return { ...guia }
    },
    async proveedores() {
      return proveedores.map((p) => ({ ...p }))
    },
  }
}

export const guiasServicio: GuiasServicio = crearGuiasApi()
