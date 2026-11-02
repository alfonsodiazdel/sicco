/** Lado mayor de la foto: basta para leer la guía y pesa unos 300 KB en vez de varios MB. */
const LADO_MAXIMO = 1600
const CALIDAD = 0.82

/**
 * Reduce la foto de la guía antes de subirla: en obra la señal suele ser débil (arquitectura §4).
 * Respeta la orientación EXIF del celular. Si el navegador no puede procesarla (p. ej. HEIC en un
 * navegador sin soporte), devuelve el archivo tal cual y el servidor decide.
 */
export async function comprimirFoto(archivo: File): Promise<Blob> {
  if (typeof createImageBitmap !== 'function') return archivo
  try {
    const imagen = await createImageBitmap(archivo, { imageOrientation: 'from-image' })
    const escala = Math.min(1, LADO_MAXIMO / Math.max(imagen.width, imagen.height))
    const lienzo = document.createElement('canvas')
    lienzo.width = Math.round(imagen.width * escala)
    lienzo.height = Math.round(imagen.height * escala)
    lienzo.getContext('2d')?.drawImage(imagen, 0, 0, lienzo.width, lienzo.height)
    imagen.close()
    const reducida = await new Promise<Blob | null>((listo) =>
      lienzo.toBlob(listo, 'image/jpeg', CALIDAD),
    )
    return reducida && reducida.size < archivo.size ? reducida : archivo
  } catch {
    return archivo
  }
}
