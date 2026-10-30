import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import TablaCrud from '@/components/organismos/TablaCrud.vue'

const columnas = [
  { campo: 'nombre', encabezado: 'Nombre' },
  { campo: 'email', encabezado: 'Correo', secundaria: true },
  { campo: 'activo', encabezado: 'Estado' },
]

type Fila = { id: number; nombre: string; email: string; activo: boolean }

const ana: Fila = { id: 1, nombre: 'Ana', email: 'ana@sicco.test', activo: true }

function montar(filas: Fila[]) {
  return mount(TablaCrud, {
    props: { filas, columnas, clave: 'id', vacio: 'No hay registros.' },
    slots: {
      'col-activo': `<template #col-activo="{ fila }">{{ fila.activo ? 'Sí' : 'No' }}</template>`,
      acciones: `<template #acciones="{ fila }"><button>Editar {{ fila.nombre }}</button></template>`,
    },
    global: { plugins: [PrimeVue] },
  })
}

describe('TablaCrud', () => {
  it('pasa las clases de las columnas como texto, sin avisos de Vue', () => {
    const avisos = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const tabla = montar([ana])

    expect(avisos.mock.calls.filter(([m]) => String(m).includes('Invalid prop'))).toEqual([])
    expect(tabla.get('th:nth-child(2)').classes()).toEqual(expect.arrayContaining(['hidden', 'sm:table-cell']))
    expect(tabla.get('td:nth-child(2)').classes()).toEqual(expect.arrayContaining(['hidden', 'sm:table-cell']))
    avisos.mockRestore()
  })

  it('muestra el campo, la celda personalizada y las acciones de cada fila', () => {
    const tabla = montar([ana])
    const celdas = tabla.findAll('tbody td').map((td) => td.text())

    expect(tabla.findAll('th').map((th) => th.text())).toEqual([
      'Nombre',
      'Correo',
      'Estado',
      'Acciones',
    ])
    expect(tabla.get('th .sr-only').text()).toBe('Acciones')
    expect(celdas).toEqual(['Ana', 'ana@sicco.test', 'Sí', 'Editar Ana'])
  })

  it('oculta las columnas secundarias en celular y fija las acciones a la derecha', () => {
    const tabla = montar([ana])
    const encabezados = tabla.findAll('th')
    const celdas = tabla.findAll('tbody td')

    for (const celda of [encabezados[1]!, celdas[1]!]) {
      expect(celda.classes()).toEqual(expect.arrayContaining(['hidden', 'sm:table-cell']))
    }
    for (const celda of [encabezados[0]!, encabezados[2]!, celdas[0]!, celdas[2]!]) {
      expect(celda.classes()).not.toContain('hidden')
    }
    for (const celda of [encabezados[3]!, celdas[3]!]) {
      expect(celda.classes()).toContain('p-datatable-frozen-column')
    }
  })

  it('alinea a la derecha y sin cortes las columnas numéricas', () => {
    const tabla = mount(TablaCrud, {
      props: {
        filas: [{ id: 1, total: '1,250.00' }],
        columnas: [{ campo: 'total', encabezado: 'Total S/', numerica: true }],
        clave: 'id',
        vacio: '',
      },
      global: { plugins: [PrimeVue] },
    })

    expect(tabla.get('th').classes()).toContain('[&>div]:justify-end')
    expect(tabla.get('tbody td').classes()).toEqual(
      expect.arrayContaining(['text-right', 'whitespace-nowrap']),
    )
  })

  it('muestra el texto de vacío sin filas', () => {
    expect(montar([]).text()).toContain('No hay registros.')
  })
})
