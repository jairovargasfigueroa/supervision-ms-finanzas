<?php

namespace App\Cobros\Infraestructura\Http;

use App\Cobros\Aplicacion\DatosNotaCobro;
use App\Cobros\Dominio\LineaNotaCobro;
use App\Compartido\Dominio\Dinero;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cuerpo de `POST /cobros/{id}/nota-cobro` (201): cliente, obra y planilla como los manda n8n. Todos los campos son
 * obligatorios; si algo falta o tiene otro tipo, `400 DATOS_INVALIDOS` con `errores[]`. La coherencia con el cobro la
 * comprueba el dominio (`422 NOTA_COBRO_INCONSISTENTE`).
 */
class NotaCobroRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El rol lo controla `rol:automatizacion` en la ruta.
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'cliente' => ['required', 'array'],
            'cliente.nombre' => ['required', 'string'],
            'obra' => ['required', 'array'],
            'obra.id' => ['required', 'uuid'],
            'obra.nombre' => ['required', 'string'],
            'planilla' => ['required', 'array'],
            'planilla.id' => ['required', 'uuid'],
            'planilla.numero' => ['required', 'integer', 'min:1'],
            'planilla.periodo' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'planilla.lineas' => ['present', 'list'],
            'planilla.lineas.*' => ['array'],
            'planilla.lineas.*.codigo' => ['required', 'string'],
            'planilla.lineas.*.nombre' => ['required', 'string'],
            'planilla.lineas.*.unidad' => ['required', 'string'],
            'planilla.lineas.*.cantidadPeriodo' => ['required', 'numeric'],
            'planilla.lineas.*.precioUnitario' => ['required', 'numeric'],
            'planilla.lineas.*.monto' => ['required', 'numeric'],
            'planilla.subtotal' => ['required', 'numeric'],
            'planilla.descuentoAnticipo' => ['required', 'numeric'],
            'planilla.retencion' => ['required', 'numeric'],
            'planilla.neto' => ['required', 'numeric'],
            'planilla.anticipoPct' => ['required', 'numeric'],
            'planilla.retencionPct' => ['required', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Es obligatorio.',
            'present' => 'Es obligatorio.',
            'array' => 'Debe ser un objeto.',
            'list' => 'Debe ser una lista.',
            'string' => 'Debe ser un texto.',
            'uuid' => 'Debe ser un UUID.',
            'integer' => 'Debe ser un número entero.',
            'min' => 'Debe ser 1 o más.',
            'numeric' => 'Debe ser un número.',
            'planilla.periodo.regex' => 'Debe ser un periodo AAAA-MM.',
        ];
    }

    public function datos(): DatosNotaCobro
    {
        /** @var array<string, mixed> $planilla */
        $planilla = $this->validated('planilla');
        /** @var list<array<string, mixed>> $lineas */
        $lineas = $planilla['lineas'];

        return new DatosNotaCobro(
            clienteNombre: $this->string('cliente.nombre')->toString(),
            obraId: $this->string('obra.id')->toString(),
            obraNombre: $this->string('obra.nombre')->toString(),
            planillaId: $this->string('planilla.id')->toString(),
            planillaNumero: $this->integer('planilla.numero'),
            periodo: $this->string('planilla.periodo')->toString(),
            lineas: array_map(fn (array $linea) => new LineaNotaCobro(
                codigo: self::texto($linea['codigo']),
                nombre: self::texto($linea['nombre']),
                unidad: self::texto($linea['unidad']),
                cantidadPeriodo: self::numero($linea['cantidadPeriodo']),
                precioUnitario: self::dinero($linea['precioUnitario']),
                monto: self::dinero($linea['monto']),
            ), $lineas),
            subtotal: self::dinero($planilla['subtotal']),
            descuentoAnticipo: self::dinero($planilla['descuentoAnticipo']),
            retencion: self::dinero($planilla['retencion']),
            neto: self::dinero($planilla['neto']),
            anticipoPct: self::numero($planilla['anticipoPct']),
            retencionPct: self::numero($planilla['retencionPct']),
        );
    }

    private static function texto(mixed $valor): string
    {
        return is_string($valor) ? $valor : '';
    }

    /** Ya validado como número (o texto numérico); el texto se redondea sin pasar por float. */
    private static function dinero(mixed $valor): Dinero
    {
        return Dinero::desde(is_int($valor) || is_float($valor) ? $valor : (is_numeric($valor) ? (string) $valor : '0'));
    }

    /** Ya validado como número (o texto numérico). */
    private static function numero(mixed $valor): float
    {
        return is_numeric($valor) ? (float) $valor : 0.0;
    }
}
