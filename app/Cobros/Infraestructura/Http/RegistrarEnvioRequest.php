<?php

namespace App\Cobros\Infraestructura\Http;

use App\Cobros\Dominio\TipoEnvioCobro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cuerpo de `POST /cobros/{id}/envios` (201): `tipo` (uno de `TipoEnvioCobro`), `destinatario` obligatorio (hasta 254
 * caracteres) y `ejecucionN8n` opcional (hasta 64). Si algo está mal, `400 DATOS_INVALIDOS` con `errores[]`.
 */
class RegistrarEnvioRequest extends FormRequest
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
            'tipo' => ['required', 'string', Rule::enum(TipoEnvioCobro::class)],
            'destinatario' => ['required', 'string', 'max:254'],
            'ejecucionN8n' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Es obligatorio.',
            'tipo.string' => 'Debe ser uno de: '.self::tipos().'.',
            'tipo.enum' => 'Debe ser uno de: '.self::tipos().'.',
            'destinatario.required' => 'Es obligatorio.',
            'destinatario.string' => 'Debe ser un texto.',
            'destinatario.max' => 'Debe tener hasta 254 caracteres.',
            'ejecucionN8n.string' => 'Debe ser un texto.',
            'ejecucionN8n.max' => 'Debe tener hasta 64 caracteres.',
        ];
    }

    public function tipo(): TipoEnvioCobro
    {
        return TipoEnvioCobro::from($this->string('tipo')->toString());
    }

    public function destinatario(): string
    {
        return $this->string('destinatario')->trim()->toString();
    }

    public function ejecucionN8n(): ?string
    {
        return $this->filled('ejecucionN8n') ? $this->string('ejecucionN8n')->trim()->toString() : null;
    }

    private static function tipos(): string
    {
        return implode(', ', array_map(fn (TipoEnvioCobro $tipo) => $tipo->value, TipoEnvioCobro::cases()));
    }
}
