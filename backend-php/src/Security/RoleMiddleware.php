<?php

declare(strict_types=1);

namespace Halcones\Security;

use Halcones\Http\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RoleMiddleware implements MiddlewareInterface
{
    public function __construct(private array $roles)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $claims = $request->getAttribute('auth', []);
        if (!in_array($claims['role'] ?? null, $this->roles, true)) {
            throw new ApiException('No tienes permiso para esta operación.', 403, 'FORBIDDEN');
        }

        return $handler->handle($request);
    }
}
