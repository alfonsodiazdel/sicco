import { http } from '@/api/http'
import { errorDeValidacion } from '@/services/errores'
import type { NivelesServicio } from '@/services/niveles'
import { decimales, volumenDisponible } from '@/services/presupuesto'
import { nombreResistencia } from '@/services/resistencias'
import type { PartidaDatos, PartidaFila } from '@/types/partidas'

export interface PartidasServicio {
  listar(proyectoId: number): Promise<PartidaFila[]>
  /**
   * `confirmado` acepta un desperdicio mayor a 15 %: sin él, la API responde 422 con
   * `requiere_confirmacion` (CU04). `confirmadoPrecio` acepta cambiar el precio de un concreto que
   * usan otras partidas del proyecto: sin él, la API responde 422 con `requiere_confirmacion_precio`.
   * La vista pide ambas antes de enviar.
   */
  crear(
    proyectoId: number,
    datos: PartidaDatos,
    confirmado?: boolean,
    confirmadoPrecio?: boolean,
  ): Promise<PartidaFila>
  actualizar(
    id: number,
    datos: PartidaDatos,
    confirmado?: boolean,
    confirmadoPrecio?: boolean,
  ): Promise<PartidaFila>
  /** Solo sin sectores (CU05): si los tiene, la API responde 422. */
  eliminar(id: number): Promise<void>
}

/** Como la guarda el servicio en memoria: si tiene sectores se calcula al leerla. */
type PartidaGuardada = Omit<PartidaFila, 'tiene_sectores'>

const partidasEjemplo: PartidaGuardada[] = [
  {
    id: 1,
    proyecto_id: 1,
    grupo_id: 2,
    item: '03.01.02.01',
    descripcion: "CONCRETO F'C=210 kg/cm2 - ZAPATAS",
    elemento: 'Zapata',
    concreto_id: 1,
    resistencia_id: 2,
    resistencia_fc: "f'c 210 kg/cm²",
    metrado_m3: 320,
    factor_desperdicio: 5,
    precio_unitario: 385,
  },
  {
    id: 2,
    proyecto_id: 1,
    grupo_id: 6,
    item: '03.02.03.01',
    descripcion: "CONCRETO PREMEZ. F'C=280 kg/cm2 - COLUMNAS",
    elemento: 'Columna',
    concreto_id: 2,
    resistencia_id: 3,
    resistencia_fc: "f'c 280 kg/cm²",
    metrado_m3: 180.5,
    factor_desperdicio: 3,
    precio_unitario: 420,
  },
  {
    id: 3,
    proyecto_id: 1,
    grupo_id: 7,
    item: null,
    descripcion: "CONCRETO F'C=210 kg/cm2 - LOSA ALIGERADA H=20cm",
    elemento: 'Losa aligerada',
    // Mismo concreto que las zapatas: comparte su precio.
    concreto_id: 1,
    resistencia_id: 2,
    resistencia_fc: "f'c 210 kg/cm²",
    metrado_m3: 245,
    factor_desperdicio: 5,
    precio_unitario: 385,
  },
  {
    id: 4,
    proyecto_id: 2,
    grupo_id: 4,
    item: '03.02.02.01',
    descripcion: "CONCRETO PREMEZ. F'C=350 kg/cm2 - PLACAS",
    elemento: 'Placa',
    concreto_id: 3,
    resistencia_id: 4,
    resistencia_fc: "f'c 350 kg/cm²",
    metrado_m3: 3850,
    factor_desperdicio: 3,
    precio_unitario: 465,
  },
]

// Import diferido: niveles depende de partidas para validar el volumen de cada sector.
const nivelesPorDefecto = async () => (await import('@/services/niveles')).nivelesServicio

export function crearPartidasEnMemoria(
  niveles: () => Promise<Pick<NivelesServicio, 'listarSectores'>> = nivelesPorDefecto,
): PartidasServicio {
  const partidas = partidasEjemplo.map((p) => ({ ...p }))
  let siguienteId = partidas.length + 1
  let siguienteConcreto = Math.max(...partidas.map((p) => p.concreto_id)) + 1

  const sectoresDe = async (proyectoId: number) => (await niveles()).listarSectores(proyectoId)
  const conSectores = (
    p: PartidaGuardada,
    sectores: { presupuesto_id: number }[],
  ): PartidaFila => ({
    ...p,
    tiene_sectores: sectores.some((s) => s.presupuesto_id === p.id),
  })

  // Como la API: el concreto del proyecto con esa resistencia lleva el precio, y lo comparten
  // todas sus partidas.
  const fijarPrecio = (proyectoId: number, datos: PartidaDatos) => {
    const mismas = partidas.filter(
      (p) => p.proyecto_id === proyectoId && p.resistencia_id === datos.resistencia_id,
    )
    mismas.forEach((p) => (p.precio_unitario = datos.precio_unitario))
    return mismas[0]?.concreto_id ?? siguienteConcreto++
  }

  return {
    async listar(proyectoId) {
      const sectores = await sectoresDe(proyectoId)
      return partidas
        .filter((p) => p.proyecto_id === proyectoId)
        .map((p) => conSectores(p, sectores))
    },
    async crear(proyectoId, datos) {
      const partida: PartidaGuardada = {
        id: siguienteId++,
        proyecto_id: proyectoId,
        concreto_id: fijarPrecio(proyectoId, datos),
        ...datos,
        resistencia_fc: nombreResistencia(datos.resistencia_id),
      }
      partidas.push({ ...partida })
      return { ...partida, tiene_sectores: false }
    },
    async actualizar(id, datos) {
      const partida = partidas.find((p) => p.id === id)
      if (!partida) throw new Error('Partida no encontrada.')
      // El metrado no puede quedar debajo de lo ya repartido: el disponible de CU05 quedaría negativo.
      const sectores = await sectoresDe(partida.proyecto_id)
      const repartido = -volumenDisponible({ id, metrado_m3: 0 }, sectores)
      if (datos.metrado_m3 < repartido) {
        throw errorDeValidacion({
          metrado_m3: [
            `El metrado es menor al volumen ya repartido en sectores (${decimales(repartido)} m³)`,
          ],
        })
      }
      const guardada = Object.assign(partida, datos, {
        concreto_id: fijarPrecio(partida.proyecto_id, datos),
        resistencia_fc: nombreResistencia(datos.resistencia_id),
      })
      return conSectores(guardada, sectores)
    },
    async eliminar(id) {
      const i = partidas.findIndex((p) => p.id === id)
      if (i < 0) throw new Error('Partida no encontrada.')
      const sectores = await sectoresDe(partidas[i]!.proyecto_id)
      if (sectores.some((s) => s.presupuesto_id === id)) {
        throw errorDeValidacion({
          partida: ['La partida tiene sectores; elimínelos para eliminarla'],
        })
      }
      partidas.splice(i, 1)
    },
  }
}

export function crearPartidasApi(): PartidasServicio {
  return {
    async listar(proyectoId) {
      return (await http.get<PartidaFila[]>(`/proyectos/${proyectoId}/partidas`)).data
    },
    async crear(proyectoId, datos, confirmado = false, confirmadoPrecio = false) {
      const cuerpo = { ...datos, confirmado, confirmado_precio: confirmadoPrecio }
      return (await http.post<PartidaFila>(`/proyectos/${proyectoId}/partidas`, cuerpo)).data
    },
    async actualizar(id, datos, confirmado = false, confirmadoPrecio = false) {
      const cuerpo = { ...datos, confirmado, confirmado_precio: confirmadoPrecio }
      return (await http.put<PartidaFila>(`/partidas/${id}`, cuerpo)).data
    },
    async eliminar(id) {
      await http.delete(`/partidas/${id}`)
    },
  }
}

export const partidasServicio: PartidasServicio = crearPartidasApi()
