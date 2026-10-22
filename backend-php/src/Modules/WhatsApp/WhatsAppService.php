<?php

declare(strict_types=1);

namespace Halcones\Modules\WhatsApp;

use GuzzleHttp\Client;
use Halcones\Infrastructure\Database;
use Halcones\Infrastructure\OutboxService;
use PDO;
use Throwable;

final class WhatsAppService
{
    private PDO $db;
    private Client $http;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->http = new Client(['timeout' => 8, 'connect_timeout' => 3]);
    }

    public function receive(string $messageId, string $sender, string $text, array $payload): void
    {
        $phone = preg_replace('/[^0-9+]/', '', $sender);
        $text = trim($text);
        if ($messageId === '' || $phone === '' || $text === '') {
            return;
        }

        $this->db->beginTransaction();
        try {
            $insertMessage = $this->db->prepare(
                'INSERT IGNORE INTO webhook_messages (provider, external_message_id, sender_phone, payload)
                 VALUES (\'META_WHATSAPP\', :external_id, :phone, :payload)'
            );
            $insertMessage->execute([
                'external_id' => $messageId,
                'phone' => $phone,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            if ($insertMessage->rowCount() === 0) {
                $this->db->commit();
                return;
            }

            $customerQuery = $this->db->prepare('SELECT id FROM users WHERE phone = :phone LIMIT 1 FOR UPDATE');
            $customerQuery->execute(['phone' => $phone]);
            $customerId = $customerQuery->fetchColumn();
            if (!$customerId) {
                $name = 'Cliente WhatsApp ' . substr($phone, -4);
                $this->db->prepare('INSERT INTO users (name, phone, role) VALUES (:name, :phone, \'CUSTOMER\')')
                    ->execute(['name' => $name, 'phone' => $phone]);
                $customerId = (int) $this->db->lastInsertId();
            }

            $this->db->prepare('INSERT INTO whatsapp_conversations (sender_phone, customer_id, expires_at)
                VALUES (:phone, :customer_id, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))
                ON DUPLICATE KEY UPDATE last_message_at = UTC_TIMESTAMP(3), expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR)')
                ->execute(['phone' => $phone, 'customer_id' => $customerId]);
            $conversationQuery = $this->db->prepare('SELECT * FROM whatsapp_conversations WHERE sender_phone = :phone FOR UPDATE');
            $conversationQuery->execute(['phone' => $phone]);
            $conversation = $conversationQuery->fetch();
            if ($conversation['expires_at'] <= gmdate('Y-m-d H:i:s')) {
                $conversation['state'] = 'AWAITING_ORIGIN';
                $conversation['draft_origin'] = null;
                $conversation['draft_destination'] = null;
            }

            if ($conversation['state'] === 'AWAITING_ORIGIN' || $conversation['state'] === 'SUBMITTED') {
                $this->db->prepare('UPDATE whatsapp_conversations SET state = \'AWAITING_DESTINATION\',
                    draft_origin = :origin, draft_destination = NULL WHERE id = :id')
                    ->execute(['origin' => mb_substr($text, 0, 500), 'id' => $conversation['id']]);
                $reply = 'Recibimos el origen: ' . mb_substr($text, 0, 350) . '. Ahora envíanos el destino.';
            } elseif ($conversation['state'] === 'AWAITING_DESTINATION') {
                $origin = trim((string) $conversation['draft_origin']);
                $destination = mb_substr($text, 0, 500);
                $publicId = OutboxService::uuid();
                $insertService = $this->db->prepare('INSERT INTO services (
                    public_id, customer_id, channel, external_request_id, origin_address, destination_address, status
                ) VALUES (:public_id, :customer_id, \'WHATSAPP\', :external_id, :origin, :destination, \'REQUESTED\')');
                $insertService->execute([
                    'public_id' => $publicId,
                    'customer_id' => $customerId,
                    'external_id' => $messageId,
                    'origin' => $origin,
                    'destination' => $destination,
                ]);
                $serviceId = (int) $this->db->lastInsertId();
                $this->db->prepare('INSERT INTO service_status_history (service_id, from_status, to_status)
                    VALUES (:service_id, NULL, \'REQUESTED\')')->execute(['service_id' => $serviceId]);
                OutboxService::append($this->db, 'service.requested', 'service', $serviceId, [
                    'service_id' => $serviceId,
                    'public_id' => $publicId,
                    'status' => 'REQUESTED',
                    'channel' => 'WHATSAPP',
                    'origin' => $origin,
                    'destination' => $destination,
                ]);
                $this->db->prepare('UPDATE whatsapp_conversations SET state = \'SUBMITTED\',
                    draft_destination = :destination WHERE id = :id')
                    ->execute(['destination' => $destination, 'id' => $conversation['id']]);
                $reply = 'Solicitud ' . substr($publicId, 0, 8) . ' creada. La central está buscando un conductor para tu viaje.';
            } else {
                $reply = 'Escríbenos el punto de recogida para iniciar una solicitud.';
            }

            $this->db->prepare('UPDATE webhook_messages SET processing_status = \'PROCESSED\', processed_at = UTC_TIMESTAMP(3)
                WHERE provider = \'META_WHATSAPP\' AND external_message_id = :external_id')
                ->execute(['external_id' => $messageId]);
            $this->db->commit();
            $this->sendText($phone, $reply);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('WhatsApp message processing failed: ' . $error->getMessage());
            throw $error;
        }
    }

    private function sendText(string $recipient, string $text): void
    {
        $phoneNumberId = $_ENV['META_PHONE_NUMBER_ID'] ?? '';
        $accessToken = $_ENV['META_ACCESS_TOKEN'] ?? '';
        if ($phoneNumberId === '' || $accessToken === '') {
            error_log('WhatsApp outbound message skipped: Meta credentials are not configured.');
            return;
        }

        $version = $_ENV['META_GRAPH_API_VERSION'] ?? 'v23.0';
        $this->http->post('https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phoneNumberId) . '/messages', [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'json' => [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipient,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $text],
            ],
        ]);
    }
}
