<?php

namespace App\Services\Documentos;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Uno de los cupos de exportación de toda la app, como lock con nombre de MySQL. Vive lo que vive la
 * conexión que lo tomó: no hay plazo que renovar, porque mientras un cliente lento no vacía el socket
 * el proceso queda bloqueado en una escritura y no puede avisar. Si el proceso muere, MySQL lo libera.
 */
class CupoExportacion
{
    private ?string $nombre = null;

    private ?int $dueno = null;

    private ?Connection $conexion = null;

    /** El lock es de todo el servidor MySQL: con el nombre de la base, cada base tiene sus propios cupos. */
    public static function nombre(int $cupo): string
    {
        $nombre = DB::connection()->getDatabaseName()."-exportar-zip-{$cupo}";

        return strlen($nombre) <= 64 ? $nombre : sha1($nombre)."-exportar-zip-{$cupo}";
    }

    /**
     * @return bool false si todos los cupos están tomados.
     *
     * @throws RuntimeException si MySQL no pudo responder si el cupo está libre.
     */
    public function tomar(): bool
    {
        $this->conexion = DB::connection();
        for ($cupo = 1; $cupo <= config('services.exportaciones.simultaneas'); $cupo++) {
            $tomado = $this->pedir(self::nombre($cupo));
            if ($tomado === null) {
                throw new RuntimeException('MySQL no respondió GET_LOCK para '.self::nombre($cupo));
            }
            if ($tomado === 1) {
                $this->nombre = self::nombre($cupo);
                $this->dueno = (int) $this->conexion->selectOne('SELECT CONNECTION_ID() AS id')->id;

                return true;
            }
        }

        return false;
    }

    /** Se puede llamar más de una vez: en el finally del envío y al terminar el proceso. */
    public function liberar(): void
    {
        if ($this->nombre === null) {
            return;
        }
        [$nombre, $this->nombre] = [$this->nombre, null];
        try {
            $dueno = $this->conexion->selectOne('SELECT IS_USED_LOCK(?) AS id', [$nombre])->id;
            if ($dueno === null || (int) $dueno !== $this->dueno) {
                self::avisar('El cupo de exportación se perdió antes de terminar: se cerró su conexión con MySQL.', ['cupo' => $nombre]);

                return;
            }
            $this->conexion->selectOne('SELECT RELEASE_LOCK(?) AS liberado', [$nombre]);
        } catch (Throwable $e) {
            self::avisar('No se pudo liberar el cupo de exportación; MySQL lo libera al cerrarse la conexión.', ['cupo' => $nombre, 'error' => $e->getMessage()]);
        }
    }

    /** También corre al apagarse el proceso, cuando el log de Laravel puede ya no existir: nunca lanza. */
    private static function avisar(string $mensaje, array $contexto): void
    {
        try {
            Log::warning($mensaje, $contexto);
        } catch (Throwable) {
            error_log($mensaje.' '.json_encode($contexto));
        }
    }

    /** 1 si lo tomó, 0 si está ocupado, null si MySQL falló. */
    protected function pedir(string $nombre): ?int
    {
        // GET_LOCK es reentrante: la misma conexión lo volvería a obtener y contaría como un cupo más.
        $tomado = $this->conexion->selectOne(
            'SELECT CASE WHEN IS_FREE_LOCK(?) = 0 THEN 0 ELSE GET_LOCK(?, 0) END AS tomado',
            [$nombre, $nombre],
        )->tomado;

        return $tomado === null ? null : (int) $tomado;
    }
}
