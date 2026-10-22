<?php

declare(strict_types=1);

namespace Halcones\Modules\WhatsApp;

use Halcones\Http\ApiException;
use Halcones\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class WhatsAppController
{
    public function __construct(private WhatsAppService $whatsapp)
    {
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $expected = $_ENV['META_VERIFY_TOKEN'] ?? '';
        $mode = (string) ($query['hub.mode'] ?? $query['hub_mode'] ?? '');
        $verifyToken = (string) ($query['hub.verify_token'] ?? $query['hub_verify_token'] ?? '');
        if ($expected === '' || $mode !== 'subscribe' || !hash_equals($expected, $verifyToken)) {
            return $response->withStatus(403);
        }
        $response->getBody()->write((string) ($query['hub.challenge'] ?? $query['hub_challenge'] ?? ''));
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    public function receive(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $appSecret = $_ENV['META_APP_SECRET'] ?? '';
        $signature = $request->getHeaderLine('X-Hub-Signature-256');
        $rawBody = (string) $request->getBody();
        if ($appSecret === '' || !preg_match('/^sha256=([a-f0-9]{64})$/i', $signature, $matches)
            || !hash_equals(hash_hmac('sha256', $rawBody, $appSecret), strtolower($matches[1]))) {
            throw new ApiException('Firma de webhook no válida.', 401, 'INVALID_WEBHOOK_SIGNATURE');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new ApiException('JSON de webhook no válido.', 400, 'INVALID_JSON');
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    if (($message['type'] ?? '') !== 'text') {
                        continue;
                    }
                    $this->whatsapp->receive(
                        (string) ($message['id'] ?? ''),
                        (string) ($message['from'] ?? ''),
                        (string) ($message['text']['body'] ?? ''),
                        $message
                    );
                }
            }
        }

        return ApiResponse::json($response, ['data' => ['received' => true]]);
    }
}
