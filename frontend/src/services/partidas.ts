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
   * `requiere_confirmacion` (CU04). La vista lo pide antes de enviar.
   */
  crear(proyectoId: number, datos: PartidaDatos, confirmado?: boolean): Promise<PartidaFila>
  actualizar(id: number, datos: PartidaDatos, confirmado?: boolean): Promise<PartidaFila>
}

const partidasEjemplo: PartidaFila[] = [
  {
    id: 1,
    proyecto_id: 1,
    grupo_id: 2,
    item: '03.01.02.01',
    descripcion: "CONCRETO F'C=210 kg/cm2 - ZAPATAS",
    elemento: 'Zapata',
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
    resistencia_id: 2,
    resistencia_fc: "f'c 210 kg/cm²",
    metrado_m3: 245,
    factor_desperdicio: 5,
    precio_unitario: 390,
  },
  {
    id: 4,
    proyecto_id: 2,
    grupo_id: 4,
    item: '03.02.02.01',
    descripcion: "CONCRETO PREMEZ. F'C=350 kg/cm2 - PLACAS",
    elemento: 'Placa',
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

  return {
    async listar(proyectoId) {
      return partidas.filter((p) => p.proyecto_id === proyectoId).map((p) => ({ ...p }))
    },
    async crear(proyectoId, datos) {
      const partida: PartidaFila = {
        id: siguienteId++,
        proyecto_id: proyectoId,
        ...datos,
        resistencia_fc: nombreResistencia(datos.resistencia_id),
      }
      partidas.push({ ...partida })
      return partida
    },
    async actualizar(id, datos) {
      const partida = partidas.find((p) => p.id === id)
      if (!partida) throw new Error('Partida no encontrada.')
      // El metrado no puede quedar debajo de lo ya repartido: el disponible de CU05 quedaría negativo.
      const sectores = await (await niveles()).listarSectores(partida.proyecto_id)
      const repartido = -volumenDisponible({ id, metrado_m3: 0 }, sectores)
      if (datos.metrado_m3 < repartido) {
        throw errorDeValidacion({
          metrado_m3: [
            `El metrado es menor al volumen ya repartido en sectores (${decimales(repartido)} m³)`,
          ],
        })
      }
      return {
        ...Object.assign(partida, datos, {
          resistencia_fc: nombreResistencia(datos.resistencia_id),
        }),
      }
    },
  }
}

export function crearPartidasApi(): PartidasServicio {
  return {
    async listar(proyectoId) {
      return (await http.get<PartidaFila[]>(`/proyectos/${proyectoId}/partidas`)).data
    },
    async crear(proyectoId, datos, confirmado = false) {
      const cuerpo = { ...datos, confirmado }
      return (await http.post<PartidaFila>(`/proyectos/${proyectoId}/partidas`, cuerpo)).data
    },
    async actualizar(id, datos, confirmado = false) {
      return (await http.put<PartidaFila>(`/partidas/${id}`, { ...datos, confirmado })).data
    },
  }
}

export const partidasServicio: PartidasServicio = crearPartidasApi()
