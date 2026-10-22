<?php

declare(strict_types=1);

use Halcones\Http\ApiException;
use Halcones\Http\ApiResponse;
use Halcones\Infrastructure\Database;
use Halcones\Modules\Auth\AuthController;
use Halcones\Modules\Auth\AuthService;
use Halcones\Modules\Drivers\DriverController;
use Halcones\Modules\Drivers\DriverService;
use Halcones\Modules\Services\RideService;
use Halcones\Modules\Services\ServiceController;
use Halcones\Modules\Realtime\BroadcastController;
use Halcones\Modules\WhatsApp\WhatsAppController;
use Halcones\Modules\WhatsApp\WhatsAppService;
use Halcones\Security\AuthMiddleware;
use Halcones\Security\JwtService;
use Halcones\Security\RoleMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;

require dirname(__DIR__) . '/config/bootstrap.php';

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $publicRoot = realpath(__DIR__);
    $requestedFile = $publicRoot === false || !is_string($requestPath)
        ? false
        : realpath($publicRoot . DIRECTORY_SEPARATOR . ltrim($requestPath, '/\\'));
    if ($requestedFile !== false && is_file($requestedFile)
        && strpos($requestedFile, $publicRoot . DIRECTORY_SEPARATOR) === 0) {
        return false;
    }
}

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$allowedOrigins = array_filter(array_map('trim', explode(',', $_ENV['API_ALLOWED_ORIGINS'] ?? '')));
$app->add(function (ServerRequestInterface $request, $handler) use ($allowedOrigins): ResponseInterface {
    $origin = $request->getHeaderLine('Origin');
    if ($request->getMethod() === 'OPTIONS') {
        $response = new Response(204);
    } else {
        $response = $handler->handle($request);
    }
    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Allow-Credentials', 'true');
    }
    return $response
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Idempotency-Key, X-Hub-Signature-256');
});

$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler(function (
    ServerRequestInterface $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app): ResponseInterface {
    $status = $exception instanceof ApiException ? $exception->status : 500;
    $code = $exception instanceof ApiException ? $exception->errorCode : 'INTERNAL_ERROR';
    $message = $exception instanceof ApiException ? $exception->getMessage() : 'Ocurrió un error interno.';
    if ($status >= 500) {
        error_log((string) $exception);
    }
    return ApiResponse::json($app->getResponseFactory()->createResponse(), [
        'error' => ['code' => $code, 'message' => $message],
    ], $status);
});

$jwt = new JwtService();
$auth = new AuthMiddleware($jwt);
$authController = new AuthController(new AuthService($jwt));
$driverController = new DriverController(new DriverService());
$serviceController = new ServiceController(new RideService());
$broadcastController = new BroadcastController();
$whatsAppController = new WhatsAppController(new WhatsAppService());
$operatorRole = new RoleMiddleware(['OPERATOR', 'ADMIN']);
$financialRole = new RoleMiddleware(['OPERATOR', 'ADMIN', 'DRIVER']);
$driverRole = new RoleMiddleware(['DRIVER']);
$serviceRole = new RoleMiddleware(['CUSTOMER', 'DRIVER', 'OPERATOR', 'ADMIN']);

$app->get('/api/v1/health', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
    Database::connection()->query('SELECT 1');
    return ApiResponse::json($response, ['data' => ['name' => 'Halcones PHP API', 'status' => 'ok']]);
});
$app->get('/api/v1/public/config', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
    $key = $_ENV['PUSHER_APP_KEY'] ?? '';
    return ApiResponse::json($response, ['data' => [
        'realtime' => [
            'enabled' => filter_var($_ENV['PUSHER_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN) && $key !== '',
            'key' => $key,
            'host' => $_ENV['PUSHER_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['PUSHER_PORT'] ?? 6001),
            'scheme' => $_ENV['PUSHER_SCHEME'] ?? 'http',
        ],
        'whatsapp_phone' => preg_replace('/[^0-9]/', '', $_ENV['WHATSAPP_OPERATOR_PHONE'] ?? ''),
    ]]);
});

$app->post('/api/v1/auth/login', [$authController, 'login']);
$app->post('/api/v1/auth/refresh', [$authController, 'refresh']);
$app->post('/api/v1/auth/logout', [$authController, 'logout'])->add($auth);
$app->get('/api/v1/auth/me', [$authController, 'me'])->add($auth);

$app->get('/api/v1/drivers/me', [$driverController, 'me'])->add($driverRole)->add($auth);
$app->patch('/api/v1/drivers/me/status', [$driverController, 'status'])->add($driverRole)->add($auth);
$app->post('/api/v1/drivers/me/location', [$driverController, 'location'])->add($driverRole)->add($auth);
$app->get('/api/v1/drivers/available', [$driverController, 'available'])->add($operatorRole)->add($auth);

$app->post('/api/v1/services', [$serviceController, 'create'])->add($serviceRole)->add($auth);
$app->get('/api/v1/services', [$serviceController, 'list'])->add($serviceRole)->add($auth);
$app->get('/api/v1/services/{id:[0-9]+}', [$serviceController, 'get'])->add($serviceRole)->add($auth);
$app->post('/api/v1/services/{id:[0-9]+}/assign', [$serviceController, 'assign'])->add($operatorRole)->add($auth);
$app->post('/api/v1/services/{id:[0-9]+}/assign/auto', [$serviceController, 'assignAuto'])->add($operatorRole)->add($auth);
$app->post('/api/v1/services/{id:[0-9]+}/actions/{action}', [$serviceController, 'action'])->add($serviceRole)->add($auth);
$app->post('/api/v1/services/{id:[0-9]+}/payments', [$serviceController, 'payment'])->add($serviceRole)->add($auth);
$app->get('/api/v1/reports/financial', [$serviceController, 'financialReport'])->add($financialRole)->add($auth);
$app->post('/api/v1/broadcasting/auth', [$broadcastController, 'authorize'])->add($auth);
$app->get('/api/v1/webhooks/whatsapp', [$whatsAppController, 'verify']);
$app->post('/api/v1/webhooks/whatsapp', [$whatsAppController, 'receive']);

$app->get('/api/v1/dashboard/summary', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
    $db = Database::connection();
    $summary = [
        'available_drivers' => (int) $db->query("SELECT COUNT(*) FROM drivers WHERE status='AVAILABLE' AND last_location_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)")->fetchColumn(),
        'active_services' => (int) $db->query("SELECT COUNT(*) FROM services WHERE status IN ('REQUESTED','PENDING_ASSIGNMENT','OFFERED','ACCEPTED','EN_ROUTE','ARRIVED','IN_PROGRESS')")->fetchColumn(),
        'completed_today' => (int) $db->query("SELECT COUNT(*) FROM services WHERE status='COMPLETED' AND completed_at >= UTC_DATE()")->fetchColumn(),
    ];
    return ApiResponse::json($response, ['data' => $summary]);
})->add($operatorRole)->add($auth);

$projectRoot = dirname(__DIR__, 2);
$pageFiles = [
    '/' => '1_cliente..html',
    '/1_cliente..html' => '1_cliente..html',
    '/2_consola.html' => '2_consola.html',
    '/3_conductor.html' => '3_conductor.html',
    '/4_detalle.html' => '4_detalle.html',
];
foreach ($pageFiles as $uri => $filename) {
    $app->get($uri, static function (ServerRequestInterface $request, ResponseInterface $response) use ($projectRoot, $filename): ResponseInterface {
        $path = $projectRoot . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new ApiException('Página no encontrada.', 404, 'NOT_FOUND');
        }
        $response->getBody()->write((string) file_get_contents($path));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });
}

$app->run();
