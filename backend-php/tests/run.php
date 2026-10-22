<?php

declare(strict_types=1);

use Halcones\Security\JwtService;

require dirname(__DIR__) . '/config/bootstrap.php';

$tests = 0;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$jwt = new JwtService();
$issued = $jwt->issue(42, 'DRIVER', 'test-session');
$claims = $jwt->decode($issued['token']);
$assert($claims['sub'] === '42', 'JWT subject should be encoded as a string.');
$assert($claims['role'] === 'DRIVER', 'JWT role should be preserved.');
$assert($claims['sid'] === 'test-session', 'JWT session id should be preserved.');
$assert(strtotime($issued['expires_at']) > time(), 'JWT should expire in the future.');
$tests++;

try {
    $jwt->decode('invalid.token.value');
    throw new RuntimeException('Invalid JWT must be rejected.');
} catch (Halcones\Http\ApiException $error) {
    $assert($error->status === 401, 'Invalid JWT should return HTTP 401.');
    $tests++;
}

echo "{$tests} tests, {$assertions} assertions passed." . PHP_EOL;
