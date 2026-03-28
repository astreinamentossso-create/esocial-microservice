<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// ==============================
// APP SETUP
// ==============================
$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

// ==============================
// CONFIG
// ==============================
$allowedOrigin = $_ENV['APP_ORIGIN'] ?? '*';
$apiToken = $_ENV['API_TOKEN'] ?? null;
$appEnv = $_ENV['ESOCIAL_ENV'] ?? 'development';

// ==============================
// HELPERS
// ==============================
function jsonResponse(Response $response, $data, int $status = 200): Response {
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $response->getBody()->write($payload);
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($status);
}

// ==============================
// CORS MIDDLEWARE
// ==============================
$app->add(function (Request $request, $handler) use ($allowedOrigin) {

    if ($request->getMethod() === 'OPTIONS') {
        $response = new \Slim\Psr7\Response();
    } else {
        $response = $handler->handle($request);
    }

    return $response
        ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
});

// ==============================
// HEALTH CHECK
// ==============================
$app->get('/health', function (Request $request, Response $response) {
    return jsonResponse($response, [
        'status' => 'ok',
        'service' => 'esocial-microservice',
        'php_version' => PHP_VERSION,
        'extensions' => [
            'soap' => extension_loaded('soap'),
            'openssl' => extension_loaded('openssl'),
            'curl' => extension_loaded('curl'),
        ],
    ]);
});

// ==============================
// AUTH MIDDLEWARE
// ==============================
$authMiddleware = function (Request $request, $handler) use ($apiToken) {

    $authHeader = $request->getHeaderLine('Authorization');

    if (!$apiToken || $authHeader !== "Bearer {$apiToken}") {
        $response = new \Slim\Psr7\Response();
        return jsonResponse($response, ['error' => 'Unauthorized'], 401);
    }

    return $handler->handle($request);
};

// ==============================
// SUBMIT EVENT
// ==============================
$app->post('/esocial/submit', function (Request $request, Response $response) use ($appEnv) {

    $body = $request->getParsedBody();

    if (!$body) {
        return jsonResponse($response, ['error' => 'Invalid JSON body'], 400);
    }

    $eventType = $body['event_type'] ?? null;
    $eventData = $body['event_data'] ?? null;
    $cnpj = $body['cnpj'] ?? null;
    $certificateBase64 = $body['certificate'] ?? null;
    $certificatePassword = $body['certificate_password'] ?? null;
    $environment = $body['environment'] ?? 'restricted_production';

    if (!$eventType || !$eventData || !$cnpj || !$certificateBase64 || !$certificatePassword) {
        return jsonResponse($response, [
            'error' => 'Missing required fields: event_type, event_data, cnpj, certificate, certificate_password',
        ], 400);
    }

    try {
        $handler = new \App\EsocialHandler();

        $result = $handler->submit(
            $eventType,
            $eventData,
            $cnpj,
            $certificateBase64,
            $certificatePassword,
            $environment
        );

        return jsonResponse($response, $result);

    } catch (\Throwable $e) {

        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $appEnv !== 'production' ? $e->getTraceAsString() : null,
        ], 500);
    }

})->add($authMiddleware);

// ==============================
// CHECK STATUS
// ==============================
$app->post('/esocial/status', function (Request $request, Response $response) use ($appEnv) {

    $body = $request->getParsedBody();

    if (!$body) {
        return jsonResponse($response, ['error' => 'Invalid JSON body'], 400);
    }

    $protocol = $body['protocol'] ?? null;
    $cnpj = $body['cnpj'] ?? null;
    $certificateBase64 = $body['certificate'] ?? null;
    $certificatePassword = $body['certificate_password'] ?? null;
    $environment = $body['environment'] ?? 'restricted_production';

    if (!$protocol || !$cnpj || !$certificateBase64 || !$certificatePassword) {
        return jsonResponse($response, [
            'error' => 'Missing required fields: protocol, cnpj, certificate, certificate_password',
        ], 400);
    }

    try {
        $handler = new \App\EsocialHandler();

        $result = $handler->checkStatus(
            $protocol,
            $cnpj,
            $certificateBase64,
            $certificatePassword,
            $environment
        );

        return jsonResponse($response, $result);

    } catch (\Throwable $e) {

        return jsonResponse($response, [
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }

})->add($authMiddleware);

// ==============================
// RUN
// ==============================
$app->run();
