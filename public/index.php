<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Middleware\ContentLengthMiddleware;

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

// CORS middleware
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
});

$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response->withStatus(200);
});

// Health check
$app->get('/health', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode([
        'status' => 'ok',
        'service' => 'esocial-microservice',
        'php_version' => PHP_VERSION,
        'extensions' => [
            'soap' => extension_loaded('soap'),
            'openssl' => extension_loaded('openssl'),
            'curl' => extension_loaded('curl'),
        ],
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Submit eSocial event
$app->post('/esocial/submit', function (Request $request, Response $response) {
    // Validate API token
    $authHeader = $request->getHeaderLine('Authorization');
    $expectedToken = getenv('API_TOKEN');
    
    if (!$expectedToken || $authHeader !== "Bearer {$expectedToken}") {
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $body = $request->getParsedBody();
    
    if (!$body) {
        $response->getBody()->write(json_encode(['error' => 'Invalid request body']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $eventType = $body['event_type'] ?? null;
    $eventData = $body['event_data'] ?? null;
    $cnpj = $body['cnpj'] ?? null;
    $certificateBase64 = $body['certificate'] ?? null;
    $certificatePassword = $body['certificate_password'] ?? null;
    $environment = $body['environment'] ?? 'restricted_production';

    if (!$eventType || !$eventData || !$cnpj || !$certificateBase64 || !$certificatePassword) {
        $response->getBody()->write(json_encode([
            'error' => 'Missing required fields: event_type, event_data, cnpj, certificate, certificate_password',
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $handler = new \App\EsocialHandler();
        $result = $handler->submit($eventType, $eventData, $cnpj, $certificateBase64, $certificatePassword, $environment);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (\Throwable $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => getenv('ESOCIAL_ENV') !== 'production' ? $e->getTraceAsString() : null,
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

// Check eSocial event status
$app->post('/esocial/status', function (Request $request, Response $response) {
    $authHeader = $request->getHeaderLine('Authorization');
    $expectedToken = getenv('API_TOKEN');
    
    if (!$expectedToken || $authHeader !== "Bearer {$expectedToken}") {
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $body = $request->getParsedBody();
    $protocol = $body['protocol'] ?? null;
    $cnpj = $body['cnpj'] ?? null;
    $certificateBase64 = $body['certificate'] ?? null;
    $certificatePassword = $body['certificate_password'] ?? null;
    $environment = $body['environment'] ?? 'restricted_production';

    if (!$protocol || !$cnpj || !$certificateBase64 || !$certificatePassword) {
        $response->getBody()->write(json_encode([
            'error' => 'Missing required fields: protocol, cnpj, certificate, certificate_password',
        ]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $handler = new \App\EsocialHandler();
        $result = $handler->checkStatus($protocol, $cnpj, $certificateBase64, $certificatePassword, $environment);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (\Throwable $e) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

$app->run();
