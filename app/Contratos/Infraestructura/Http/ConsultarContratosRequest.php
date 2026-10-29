<?php

namespace App\Contratos\Infraestructura\Http;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Consulta de `GET /contratos`: `obraId` (uuid) obligatorio. Si no es válido, `400 DATOS_INVALIDOS` con `errores[]`.
 */
class ConsultarContratosRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El rol lo controla `rol:admin,gerente` en la ruta.
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'obraId' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'obraId.required' => 'Es obligatorio.',
            'obraId.uuid' => 'Debe ser un UUID.',
        ];
    }

    public function obraId(): string
    {
        return $this->string('obraId')->toString();
    }
}
