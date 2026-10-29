<?php

namespace App\Cobros\Aplicacion\CasosUso;

use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Dominio\Cobro;
use App\Compartido\Dominio\Dinero;
use App\Compartido\Dominio\FechaNegocio;
use App\Contratos\Aplicacion\Puertos\ContratoRepositorio;
use App\Contratos\Aplicacion\Puertos\PublicadorEventos;
use App\Contratos\Aplicacion\Puertos\ReservaSaldoRepositorio;
use App\Contratos\Dominio\ReservaSaldo;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Atiende `EmitirCobro` (008, paso 4 de la saga): si la reserva está `RESERVADA`, crea el cobro (`COB-<año>-NNNN`,
 * emitido hoy en La Paz, vence a los 30 días, `PENDIENTE`), marca la reserva `CONSUMIDA` y responde
 * `CobroEmitido {planillaId, cobroId, numero, venceEn}`.
 *
 * - Si la planilla ya tiene cobro, responde `CobroEmitido` con el existente (no emite otro).
 * - Si la reserva no está `RESERVADA` (por ejemplo, ya se liberó), `CobroFallido` con «No se pudo emitir el cobro: la
 *   reserva de saldo ya no está vigente».
 * - Ante cualquier otro error al emitir (reserva inexistente o de otra planilla, monto distinto del reservado, error al
 *   guardar), `CobroFallido` con «No se pudo emitir el cobro por un error en Finanzas».
 */
final readonly class EmitirCobro
{
    /** Motivos de `CobroFallido`, textos exactos de la spec 008 (diseño, docs/mensajes.md). */
    public const MOTIVO_RESERVA_NO_VIGENTE = 'No se pudo emitir el cobro: la reserva de saldo ya no está vigente';

    public const MOTIVO_ERROR = 'No se pudo emitir el cobro por un error en Finanzas';

    public function __construct(
        private CobroRepositorio $cobros,
        private ReservaSaldoRepositorio $reservas,
        private ContratoRepositorio $contratos,
        private PublicadorEventos $eventos,
    ) {}

    public function ejecutar(string $planillaId, string $reservaId, Dinero $monto, string $correlacionId, DateTimeImmutable $ahora): ResultadoEmitirCobro
    {
        return DB::transaction(function () use ($planillaId, $reservaId, $monto, $correlacionId, $ahora) {
            // Se bloquea la reserva antes de buscar el cobro: si llegan dos EmitirCobro a la vez, el segundo espera y
            // ve el cobro del primero.
            $reserva = $this->reservas->bloquear($reservaId);

            $existente = $this->cobros->deLaPlanilla($planillaId);
            if ($existente !== null) {
                $this->publicarEmitido($existente, $correlacionId);

                return ResultadoEmitirCobro::YaEmitido;
            }

            if ($reserva === null || $reserva->planillaId !== $planillaId || ! $reserva->monto->esIgualA($monto)) {
                Log::error('EmitirCobro: la reserva no existe, es de otra planilla o tiene otro monto.', [
                    'planillaId' => $planillaId,
                    'reservaId' => $reservaId,
                ]);

                return $this->fallar($planillaId, self::MOTIVO_ERROR, $correlacionId);
            }
            if (! $reserva->estaVigente()) {
                return $this->fallar($planillaId, self::MOTIVO_RESERVA_NO_VIGENTE, $correlacionId);
            }

            try {
                // Punto de guardado: si la emisión falla, se deshace solo ella y se responde CobroFallido.
                $cobro = DB::transaction(fn () => $this->emitir($reserva, $monto, $ahora));
            } catch (Throwable $error) {
                Log::error('EmitirCobro: falló la emisión del cobro.', [
                    'planillaId' => $planillaId,
                    'reservaId' => $reservaId,
                    'error' => $error->getMessage(),
                ]);

                return $this->fallar($planillaId, self::MOTIVO_ERROR, $correlacionId);
            }

            $this->publicarEmitido($cobro, $correlacionId);

            return ResultadoEmitirCobro::Emitido;
        });
    }

    private function emitir(ReservaSaldo $reserva, Dinero $monto, DateTimeImmutable $ahora): Cobro
    {
        // Quien calcula el saldo del contrato (ReservarSaldo) no debe ver el cobro creado y la reserva todavía vigente.
        $this->contratos->bloquear($reserva->contratoId);

        $emitidoEn = FechaNegocio::hoy($ahora);
        $cobro = Cobro::emitir(
            id: $this->cobros->siguienteId(),
            contratoId: $reserva->contratoId,
            planillaId: $reserva->planillaId,
            correlativo: $this->cobros->siguienteCorrelativo((int) $emitidoEn->format('Y')),
            montoNeto: $monto,
            emitidoEn: $emitidoEn,
        );
        $this->cobros->guardar($cobro);
        $reserva->consumir();
        $this->reservas->guardar($reserva);

        return $cobro;
    }

    private function publicarEmitido(Cobro $cobro, string $correlacionId): void
    {
        $this->eventos->publicar('CobroEmitido', [
            'planillaId' => $cobro->planillaId,
            'cobroId' => $cobro->id,
            'numero' => $cobro->numero,
            'venceEn' => $cobro->venceEn->format('Y-m-d'),
        ], $correlacionId);
    }

    private function fallar(string $planillaId, string $motivo, string $correlacionId): ResultadoEmitirCobro
    {
        $this->eventos->publicar('CobroFallido', ['planillaId' => $planillaId, 'motivo' => $motivo], $correlacionId);

        return ResultadoEmitirCobro::Fallido;
    }
}
