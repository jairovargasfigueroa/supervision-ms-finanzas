<?php

namespace App\Compartido\Infraestructura\Semilla;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vacía la base del servicio para volver a cargar los datos de prueba (011, `recargar` de infra): todas las tablas
 * salvo `migrations` (el historial de migraciones). Así entran solas las tablas que agreguen las capas (por ejemplo
 * las de la 201); las propias de Laravel (`cache`, `jobs`, `sessions`…) también se vacían, que en desarrollo no
 * guardan nada que haga falta. Todo en una transacción: o se vacía todo o nada.
 */
final class VaciadorTablas
{
    /** @var list<string> */
    public const CONSERVADAS = ['migrations'];

    /**
     * @return list<string> las tablas vaciadas, sin el esquema
     */
    public function vaciar(): array
    {
        $tablas = $this->tablas();
        if ($tablas === []) {
            return [];
        }

        DB::transaction(function () use ($tablas): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                // Una sola sentencia para todas: CASCADE resuelve las claves foráneas entre ellas.
                $lista = implode(', ', array_map(fn (string $tabla): string => DB::getQueryGrammar()->wrapTable($tabla), $tablas));
                DB::statement("TRUNCATE TABLE {$lista} RESTART IDENTITY CASCADE");

                return;
            }

            // SQLite (pruebas): las claves foráneas se revisan al confirmar, cuando ya está todo vacío.
            DB::statement('PRAGMA defer_foreign_keys = ON');
            foreach ($tablas as $tabla) {
                DB::table($tabla)->delete();
            }
        });

        return array_map(fn (string $tabla): string => self::sinEsquema($tabla), $tablas);
    }

    /**
     * Tablas del esquema actual con su esquema (`public.contrato`), menos las que se conservan.
     *
     * @return list<string>
     */
    private function tablas(): array
    {
        $tablas = Schema::getTableListing(Schema::getCurrentSchemaListing());

        return array_values(array_filter(
            $tablas,
            fn (string $tabla): bool => ! in_array(self::sinEsquema($tabla), self::CONSERVADAS, true),
        ));
    }

    private static function sinEsquema(string $tabla): string
    {
        $partes = explode('.', $tabla);

        return end($partes);
    }
}
