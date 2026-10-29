<?php

namespace App\Cobros\Infraestructura\Http;

use App\Cobros\Aplicacion\Puertos\CobroRepositorio;
use App\Cobros\Dominio\EstadoCobro;
use App\Compartido\Dominio\CriteriosPagina;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Consulta de `GET /cobros`: filtros `obraId` (uuid) y `estado` (`PENDIENTE` o `VENCIDO`), ambos opcionales, y
 * paginación (docs/convenciones.md). Si algo está mal formado, `400 DATOS_INVALIDOS` con `errores[]`; el `tamano` mayor
 * a 100 lo rechaza `CriteriosPagina` con `400 PAGINACION_INVALIDA`.
 */
class ListarCobrosRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El rol lo controla `rol:admin,gerente` en la ruta.
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'obraId' => ['sometimes', 'uuid'],
            'estado' => ['sometimes', Rule::enum(EstadoCobro::class)],
            'pagina' => ['sometimes', 'integer', 'min:1'],
            'tamano' => ['sometimes', 'integer', 'min:1'],
            'orden' => ['sometimes', Rule::in(CobroRepositorio::ORDENES)],
            'direccion' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'obraId.uuid' => 'Debe ser un UUID.',
            'estado.enum' => 'Debe ser PENDIENTE o VENCIDO.',
            'pagina.integer' => 'Debe ser un número entero.',
            'pagina.min' => 'Debe ser 1 o más.',
            'tamano.integer' => 'Debe ser un número entero.',
            'tamano.min' => 'Debe ser 1 o más.',
            'orden.in' => 'Debe ser uno de: '.implode(', ', CobroRepositorio::ORDENES).'.',
            'direccion.in' => 'Debe ser asc o desc.',
        ];
    }

    public function obraId(): ?string
    {
        return $this->filled('obraId') ? $this->string('obraId')->toString() : null;
    }

    public function estado(): ?EstadoCobro
    {
        return $this->filled('estado') ? EstadoCobro::from($this->string('estado')->toString()) : null;
    }

    public function criterios(): CriteriosPagina
    {
        return new CriteriosPagina(
            pagina: $this->integer('pagina', 1),
            tamano: $this->integer('tamano', CriteriosPagina::TAMANO_POR_DEFECTO),
            orden: $this->string('orden', 'venceEn')->toString(),
            direccion: $this->string('direccion', 'asc')->toString(),
        );
    }
}
