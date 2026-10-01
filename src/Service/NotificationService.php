<?php

declare(strict_types=1);

namespace MauKirim\Service;

/**
 * Templated notifications sent from a shared MauKirim number (scope `notification`).
 *
 * Unlike a rented number, the sender is chosen by MauKirim: you supply a phone
 * number, an approved template and its variables.
 */
final class NotificationService extends AbstractService
{
    /**
     * Sends one templated notification.
     *
     * `POST /messages/send` — scope `notification`, `Idempotency-Key` required.
     * `variables` keys must equal the template's top-level placeholder names
     * exactly: each scalar is 1-64 characters, and a list placeholder takes an
     * array of 0-10 objects whose fields match that section's keys.
     *
     * @param string               $phone                Recipient in E.164 form.
     * @param string               $templateId           An approved template id.
     * @param array<string, mixed> $variables            Placeholder values: `string` or `list<array<string, string>>`.
     * @param string|null          $attachmentUploadId   `media.id` from {@see NotificationService::uploadMedia()}; only for
     *                                                   per-send templates.
     * @param string               $idempotencyKey       Unique key, 1-128 characters.
     *
     * @return array{ok: true, batchId: string}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function send(
        string $phone,
        string $templateId,
        array $variables,
        ?string $attachmentUploadId,
        string $idempotencyKey,
    ): array {
        $body = [
            'phone' => $phone,
            'templateId' => $templateId,
            // An empty map must encode as {} rather than [], so a template
            // without placeholders still receives an object.
            'variables' => $variables === [] ? new \stdClass() : $variables,
        ];

        if ($attachmentUploadId !== null) {
            $body['attachmentUploadId'] = $attachmentUploadId;
        }

        return $this->client->call('POST', '/messages/send', [
            'json' => $body,
            'idempotency_key' => $idempotencyKey,
            'idempotency_required' => true,
        ]);
    }

    /**
     * Uploads the attachment for a per-send template, or any file you want a
     * public `media.id` for.
     *
     * `POST /messages/media` — scope `notification`, `multipart/form-data` with
     * a single `file` part. JPEG/PNG up to 5 MB; PDF/Office/CSV/TXT/ZIP up to
     * 16 MB. Uploads expire after 7 days.
     *
     * @param string $fileName    Name reported back by the API, e.g. `receipt.pdf`.
     * @param string $contentType Part content type, e.g. `application/pdf`.
     * @param string $bytes       Raw file contents.
     *
     * @return array{ok: true, media: array{
     *     id: string,
     *     contentType: string,
     *     byteSize: int,
     *     fileName: string|null,
     *     kind: 'image'|'file',
     *     expiresAt: string
     * }}
     *
     * @throws \MauKirim\Exception\MauKirimException
     */
    public function uploadMedia(string $fileName, string $contentType, string $bytes): array
    {
        return $this->client->call('POST', '/messages/media', [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => $bytes,
                    'filename' => $fileName,
                    'contentType' => $contentType,
                ],
            ],
        ]);
    }
}
