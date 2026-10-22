<?php

declare(strict_types=1);

namespace Halcones\Security;

use Halcones\Http\ApiException;
use Halcones\Infrastructure\Database;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new ApiException('Se requiere autenticación.', 401, 'UNAUTHENTICATED');
        }
        $claims = $this->jwt->decode($matches[1]);
        $query = Database::connection()->prepare(
            'SELECT 1 FROM auth_sessions s JOIN users u ON u.id = s.user_id
             WHERE s.id = :session_id AND s.user_id = :user_id AND s.revoked_at IS NULL
               AND s.access_expires_at > UTC_TIMESTAMP() AND u.account_status = \'ACTIVE\' LIMIT 1'
        );
        $query->execute(['session_id' => $claims['sid'], 'user_id' => (int) $claims['sub']]);
        if (!$query->fetchColumn()) {
            throw new ApiException('La sesión fue revocada o venció.', 401, 'UNAUTHENTICATED');
        }

        return $handler->handle($request->withAttribute('auth', $claims));
    }
}
