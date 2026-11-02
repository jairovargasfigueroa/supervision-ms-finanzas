<?php

namespace App\Cobros\Infraestructura\Http;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cuerpo de `POST /cobros/{id}/pago` (201): `pagadoEn` obligatorio (`AAAA-MM-DD`) y `comprobante` opcional, de 1 a 50
 * caracteres. Los espacios de los extremos se quitan y un texto vacío llega como null (middleware globales de
 * Laravel). Si algo está mal, `400 DATOS_INVALIDOS` con `errores[]`.
 */
class RegistrarPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El rol lo controla `rol:admin` en la ruta.
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'pagadoEn' => ['required', 'string', 'date_format:Y-m-d'],
            'comprobante' => ['nullable', 'string', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pagadoEn.required' => 'Es obligatoria.',
            'pagadoEn.string' => 'Debe ser una fecha AAAA-MM-DD.',
            'pagadoEn.date_format' => 'Debe ser una fecha AAAA-MM-DD.',
            'comprobante.string' => 'Debe ser un texto.',
            'comprobante.min' => 'Debe tener de 1 a 50 caracteres.',
            'comprobante.max' => 'Debe tener de 1 a 50 caracteres.',
        ];
    }

    public function pagadoEn(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->string('pagadoEn')->toString(), new DateTimeZone('America/La_Paz'));
    }

    public function comprobante(): ?string
    {
        return $this->filled('comprobante') ? $this->string('comprobante')->trim()->toString() : null;
    }
}
