<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Nota de cobro {{ $numero }}</title>
    {{-- 201: dompdf no admite flexbox ni grid; todo va en tablas. Helvetica es una fuente base del PDF (no se incrusta). --}}
    <style>
        @page { margin: 28mm 18mm 22mm 18mm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; color: #1f2937; }
        h1 { font-size: 16pt; margin: 0; color: #1e3a5f; }
        h2 { font-size: 12pt; margin: 0 0 4pt 0; }
        table { width: 100%; border-collapse: collapse; }
        .encabezado td { vertical-align: top; }
        .derecha { text-align: right; }
        .centro { text-align: center; }
        .datos td { padding: 2pt 0; }
        .etiqueta { color: #6b7280; width: 30%; }
        .lineas { margin-top: 12pt; }
        .lineas th { background: #1e3a5f; color: #ffffff; font-weight: bold; padding: 4pt; font-size: 9pt; }
        .lineas td { border-bottom: 1px solid #d1d5db; padding: 4pt; font-size: 9pt; }
        .totales { margin-top: 10pt; width: 55%; margin-left: 45%; }
        .totales td { padding: 3pt 4pt; }
        .neto td { border-top: 2px solid #1e3a5f; font-weight: bold; font-size: 11pt; }
        .leyenda { margin-top: 24pt; padding: 6pt; border: 1px solid #9ca3af; text-align: center; font-weight: bold; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            <td>
                <h1>{{ $empresa }}</h1>
            </td>
            <td class="derecha">
                <h2>Nota de cobro</h2>
                <div>N.º {{ $numero }}</div>
            </td>
        </tr>
    </table>

    <table class="datos" style="margin-top: 14pt;">
        <tr><td class="etiqueta">Cliente</td><td>{{ $cliente }}</td></tr>
        <tr><td class="etiqueta">Obra</td><td>{{ $obra }}</td></tr>
        <tr><td class="etiqueta">Planilla</td><td>N.º {{ $planillaNumero }} · {{ $periodo }}</td></tr>
        <tr><td class="etiqueta">Fecha de emisión</td><td>{{ $emitidoEn }}</td></tr>
        <tr><td class="etiqueta">Vencimiento</td><td>{{ $venceEn }}</td></tr>
    </table>

    <table class="lineas">
        <thead>
            <tr>
                <th>Código</th>
                <th>Partida</th>
                <th class="centro">Unidad</th>
                <th class="derecha">Cantidad del periodo</th>
                <th class="derecha">Precio unitario</th>
                <th class="derecha">Monto</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lineas as $linea)
                <tr>
                    <td>{{ $linea['codigo'] }}</td>
                    <td>{{ $linea['nombre'] }}</td>
                    <td class="centro">{{ $linea['unidad'] }}</td>
                    <td class="derecha">{{ $linea['cantidad'] }}</td>
                    <td class="derecha">{{ $linea['precioUnitario'] }}</td>
                    <td class="derecha">{{ $linea['monto'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr><td>Subtotal</td><td class="derecha">{{ $subtotal }}</td></tr>
        <tr><td>Descuento por anticipo ({{ $anticipoPct }} %)</td><td class="derecha">- {{ $descuentoAnticipo }}</td></tr>
        <tr><td>Retención ({{ $retencionPct }} %)</td><td class="derecha">- {{ $retencion }}</td></tr>
        <tr class="neto"><td>Neto a cobrar</td><td class="derecha">{{ $neto }}</td></tr>
    </table>

    <div class="leyenda">{{ $leyenda }}</div>
</body>
</html>
