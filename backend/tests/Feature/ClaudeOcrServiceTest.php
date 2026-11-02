<?php

use Anthropic\Client;
use App\Services\Ocr\ClaudeOcrService;
use App\Services\Ocr\LecturaNoDisponible;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;

/**
 * El SDK de Anthropic con un cliente HTTP falso: verifica la petición que se envía y cómo se
 * interpreta la respuesta, sin llamar a la API.
 */
function ocrClaude(array $respuestas, array &$enviadas = []): ClaudeOcrService
{
    $pila = HandlerStack::create(new MockHandler($respuestas));
    $pila->push(Middleware::history($enviadas));
    $cliente = new Client(apiKey: 'sk-ant-prueba', requestOptions: [
        'transporter' => new GuzzleClient(['handler' => $pila]),
        'maxRetries' => 0,
    ]);

    return new ClaudeOcrService($cliente, 'claude-opus-5-5');
}

function respuestaClaude(array|string $datos, string $stopReason = 'end_turn'): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id' => 'msg_01',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => [['type' => 'text', 'text' => is_string($datos) ? $datos : json_encode($datos)]],
        'stop_reason' => $stopReason,
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 1600, 'output_tokens' => 300],
    ]));
}

function imagenGuia(): string
{
    return UploadedFile::fake()->image('guia.jpg', 3000, 4000)->get();
}

it('envía la foto reducida con el esquema y lee la respuesta', function () {
    $enviadas = [];
    $ocr = ocrClaude([respuestaClaude([
        'es_guia_remision' => true,
        'numero' => ['valor' => 't001-00012345', 'confianza' => 0.98],
        'fecha' => ['valor' => today()->toDateString(), 'confianza' => 0.9],
        'proveedor' => ['valor' => 'UNICON S.A.', 'confianza' => 0.95],
        'ruc' => ['valor' => '20100030595', 'confianza' => 0.97],
        'codigo_producto' => ['valor' => null, 'confianza' => 0],
        'descripcion_producto' => ['valor' => "CONCRETO F'C 210", 'confianza' => 0.9],
        'resistencia' => ['valor' => 210, 'confianza' => 0.9],
        'volumen_m3' => ['valor' => 8.5, 'confianza' => 0.99],
        'placa' => ['valor' => null, 'confianza' => 0],
    ])], $enviadas);

    $lectura = $ocr->leerGuia(imagenGuia(), 'image/jpeg', ['HOLCIM PERU S.A. (RUC 20380289360): en el código.']);

    expect($lectura->valor('numero'))->toBe('T001-00012345')
        ->and($lectura->valor('resistencia'))->toBe(210)
        ->and($lectura->valor('volumen_m3'))->toBe(8.5)
        ->and($lectura->valor('placa'))->toBeNull()
        ->and($lectura->confianzaMinima())->toBe(0.9)
        ->and($lectura->payload['modelo'])->toBe('claude-opus-5-5');

    /** @var Request $peticion */
    $peticion = $enviadas[0]['request'];
    $cuerpo = json_decode((string) $peticion->getBody(), true);
    $imagen = $cuerpo['messages'][0]['content'][0];

    expect((string) $peticion->getUri())->toBe('https://api.anthropic.com/v1/messages')
        ->and($peticion->getHeaderLine('anthropic-beta'))->toContain('server-side-fallback-2026-07-01')
        ->and($peticion->getHeaderLine('Authorization'))->toBe('Bearer sk-ant-prueba')
        ->and($cuerpo['model'])->toBe('claude-opus-5-5')
        ->and($cuerpo['fallbacks'])->toBe('default')
        ->and($cuerpo['output_config']['format']['type'])->toBe('json_schema')
        ->and($cuerpo['output_config']['format']['schema']['required'])->toContain('numero', 'codigo_producto', 'es_guia_remision')
        ->and($cuerpo['output_config']['format']['schema']['properties']['resistencia']['properties']['valor']['type'])->toBe(['integer', 'null'])
        ->and($cuerpo['system'])->toEndWith('Indicaciones por concretera:
- HOLCIM PERU S.A. (RUC 20380289360): en el código.')
        ->and($imagen['source']['media_type'])->toBe('image/jpeg')
        ->and(array_slice(getimagesizefromstring(base64_decode($imagen['source']['data'])), 0, 2))->toBe([1176, 1568]);
});

it('no da lectura si la imagen no es una guía', function () {
    ocrClaude([respuestaClaude(['es_guia_remision' => false])])->leerGuia(imagenGuia(), 'image/jpeg');
})->throws(LecturaNoDisponible::class);

it('no da lectura si la respuesta queda incompleta', function () {
    ocrClaude([respuestaClaude('{"es_guia', 'max_tokens')])->leerGuia(imagenGuia(), 'image/jpeg');
})->throws(LecturaNoDisponible::class);

it('no da lectura si el servicio no responde a tiempo', function () {
    ocrClaude([new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://api.anthropic.com/v1/messages'))])
        ->leerGuia(imagenGuia(), 'image/jpeg');
})->throws(LecturaNoDisponible::class);

it('no da lectura si la API responde con error', function () {
    ocrClaude([new Response(529, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}')])
        ->leerGuia(imagenGuia(), 'image/jpeg');
})->throws(LecturaNoDisponible::class);
