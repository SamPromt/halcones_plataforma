<?php

declare(strict_types=1);

namespace Halcones\Modules\Auth;

use Halcones\Http\ApiException;
use Halcones\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function __construct(private AuthService $auth)
    {
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $phone = trim((string) ($body['phone'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($phone === '' || $password === '' || strlen($phone) > 20 || strlen($password) > 200) {
            throw new ApiException('Teléfono y contraseña son obligatorios.', 422, 'VALIDATION_ERROR');
        }

        return ApiResponse::json($response, ['data' => $this->auth->login($phone, $password)]);
    }

    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $token = trim((string) ($body['refresh_token'] ?? ''));
        if ($token === '' || strlen($token) > 200) {
            throw new ApiException('refresh_token es obligatorio.', 422, 'VALIDATION_ERROR');
        }

        return ApiResponse::json($response, ['data' => $this->auth->refresh($token)]);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $claims = $request->getAttribute('auth');
        $this->auth->logout((int) $claims['sub'], $claims['sid']);

        return ApiResponse::json($response, ['data' => ['logged_out' => true]]);
    }

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $claims = $request->getAttribute('auth');

        return ApiResponse::json($response, ['data' => $this->auth->currentUser((int) $claims['sub'])]);
    }
}
