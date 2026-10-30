import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import TablaCrud from '@/components/organismos/TablaCrud.vue'

const columnas = [
  { campo: 'nombre', encabezado: 'Nombre' },
  { campo: 'activo', encabezado: 'Estado' },
]

function montar(filas: { id: number; nombre: string; activo: boolean }[]) {
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
  it('muestra el campo, la celda personalizada y las acciones de cada fila', () => {
    const tabla = montar([{ id: 1, nombre: 'Ana', activo: true }])
    const celdas = tabla.findAll('tbody td').map((td) => td.text())

    expect(tabla.findAll('th').map((th) => th.text())).toEqual(['Nombre', 'Estado', 'Acciones'])
    expect(tabla.get('th .sr-only').text()).toBe('Acciones')
    expect(celdas).toEqual(['Ana', 'Sí', 'Editar Ana'])
  })

  it('muestra el texto de vacío sin filas', () => {
    expect(montar([]).text()).toContain('No hay registros.')
  })
})
