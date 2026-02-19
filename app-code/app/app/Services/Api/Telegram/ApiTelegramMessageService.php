<?php

declare(strict_types=1);

namespace App\Services\Api\Telegram;

use App\Enums\Telegram\TelegramChatMessageActionEnum;
use Exception;
use Illuminate\Support\Facades\Log;
use Longman\TelegramBot\Entities\File;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Storage;

final class ApiTelegramMessageService
{
    /**
     * Sends a message to Telegram chat
     * Falls back to plain text message if markdown parsing fails
     *
     * @param  int  $chat_id  Chat identifier
     * @param  array  $params  Additional parameters for the message
     * @return ServerResponse Response from Telegram API or null on error
     *
     * @throws TelegramException
     */
    public function sendMessage(int $chat_id, array $params): ServerResponse
    {
        $data = [
            'chat_id' => $chat_id,
        ];

        if (array_key_exists('parse_mode', $params) && $params['parse_mode'] === null) {
            unset($params['parse_mode']);
        }

        $server_response = Request::sendMessage(array_merge($data, $params));

        // If error is related to entity parsing (markdown formatting), retry with plain text
        if (! $server_response->isOk() &&
            $server_response->getErrorCode() === 400 &&
            str_contains($server_response->getDescription(), "can't parse entities")) {

            $plain_params = $params;
            // Remove parse_mode to send as plain text
            unset($plain_params['parse_mode'], $data['parse_mode']);

            Log::channel('stack')->info(
                'Retrying to send message without formatting',
                [
                    'chat_id' => $chat_id,
                ]
            );

            // Retry sending without formatting
            $server_response = Request::sendMessage(array_merge($data, $plain_params));
        }

        if ($server_response->isOk()) {
            return $server_response;
        }

        Log::channel('stack')->error(
            'Telegram sendMessage error: '.$server_response->getDescription(),
            [
                'error_code' => $server_response->getErrorCode(),
                'chat_id'    => $chat_id,
                'params'     => $params,
            ]
        );

        return Request::emptyResponse();
    }

    public function deleteMessage(int $chat_id, int $message_id): ServerResponse
    {
        $data = [
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
        ];

        $server_response = Request::deleteMessage($data);

        if ($server_response->isOk()) {
            return $server_response;
        }

        return Request::emptyResponse();
    }

    public function answerCallbackQuery(array $params): ServerResponse
    {
        $data = [
            'show_alert' => false,
        ];

        return Request::answerCallbackQuery(array_merge($data, $params));
    }

    public function sendChatAction(int $chat_id, TelegramChatMessageActionEnum $chat_message_action_enum): ServerResponse
    {
        $data = [
            'chat_id' => $chat_id,
            'action'  => $chat_message_action_enum->value,
        ];

        $server_response = Request::sendChatAction($data);

        if ($server_response->isOk()) {
            return $server_response;
        }

        return Request::emptyResponse();
    }

    /**
     * @throws TelegramException
     */
    public function sendPhoto(int $chat_id, string $photo_path, array $params = []): ServerResponse
    {
        $data = [
            'chat_id' => (string) $chat_id,
            'photo'   => Request::encodeFile(Storage::path($photo_path)),
        ];

        if (! empty($params)) {
            $data = array_merge($data, $params);
        }

        $server_response = Request::sendPhoto($data);

        if ($server_response->isOk()) {
            return $server_response;
        }

        return Request::emptyResponse();
    }

    /**
     * Send document to Telegram chat with automatic type detection
     * Determines document format and sends with appropriate MIME type
     *
     * @param  int  $chat_id  Chat identifier
     * @param  string  $file_path  Path to file (using Laravel Storage)
     * @param  array  $params  Additional parameters for the document
     * @param  string  $custom_name  Custom filename (optional)
     * @return ServerResponse Response from Telegram API or null on error
     */
    public function sendDocument(int $chat_id, string $file_path, array $params = [], string $custom_name = ''): ServerResponse
    {
        try {
            // Check if file exists
            if (! Storage::exists($file_path)) {
                Log::channel('stack')->warning('Document file not found', [
                    'path' => $file_path,
                ]);

                return Request::emptyResponse();
            }

            $filename = ($custom_name ?: basename($file_path));

            // Prepare data for sending document
            $data = [
                'chat_id'  => $chat_id,
                'document' => Request::encodeFile(Storage::path($file_path)),
            ];

            // Merge additional parameters
            if (! empty($params)) {
                $data = array_merge($data, $params);
            }

            // Send document
            $server_response = Request::sendDocument($data);

            if ($server_response->isOk()) {
                return $server_response;
            }

            Log::channel('stack')->error(
                'Telegram sendDocument error: '.$server_response->getDescription(),
                [
                    'error_code' => $server_response->getErrorCode(),
                    'chat_id'    => $chat_id,
                    'filename'   => $filename,
                    'params'     => $params,
                ]
            );

            return Request::emptyResponse();
        } catch (Exception $e) {
            Log::channel('stack')->error('Exception in sendDocument', [
                'error'     => $e->getMessage(),
                'chat_id'   => $chat_id,
                'file_path' => $file_path,
            ]);

            return Request::emptyResponse();
        }
    }

    /**
     * Download and save photos from message
     *
     *
     * @return array|null ['photo' => path, 'caption' => caption] or null
     *
     * @throws TelegramException
     */
    public function downloadPhotosFromUpdate(Update $update): ?array
    {
        $photos = get_telegram_photo($update);

        if (empty($photos)) {
            return null;
        }

        // Get largest photo (last element in array)
        $photo   = end($photos);
        $file_id = $photo->getFileId();

        // Get file info from Telegram
        $response = Request::getFile(['file_id' => $file_id]);

        if (! $response->isOk()) {
            throw new TelegramException('Failed to get file info: '.$response->getDescription());
        }

        $file      = $response->getResult();
        $file_path = $file->getFilePath();

        // Build download URL
        $api_token    = config('telegram.bot_token');
        $download_url = "https://api.telegram.org/file/bot$api_token/$file_path";

        // Download file content
        $file_content = @file_get_contents($download_url);

        if ($file_content === false) {
            throw new TelegramException('Failed to download photo from Telegram');
        }

        // Generate unique filename
        $extension    = pathinfo($file_path, PATHINFO_EXTENSION) ?: 'jpg';
        $filename     = $photo->getFileUniqueId().'.'.$extension;
        $storage_path = 'telegram/photos/'.date('Y/m/').$filename;
        $directory    = dirname($storage_path);
        $message      = get_telegram_message($update);

        // Create directory if not exists
        if (! Storage::directoryExists($directory)) {
            Storage::makeDirectory($directory);
        }

        // Save file content using put method
        Storage::put($storage_path, $file_content);

        return [
            'photo'   => $storage_path,
            'caption' => $message->getCaption(),
        ];
    }

    /**
     * Download and save document from message
     *
     *
     * @return array|null ['document' => path, 'caption' => caption] or null
     *
     * @throws TelegramException
     */
    public function downloadDocumentFromUpdate(Update $update): ?array
    {
        $document = get_telegram_doc($update);

        if ($document === null) {
            return null;
        }

        $file_id = $document->getFileId();

        // Get file info from Telegram
        $response = Request::getFile(['file_id' => $file_id]);

        if (! $response->isOk()) {
            throw new TelegramException('Failed to get file info: '.$response->getDescription());
        }

        /** @var File $file */
        $file      = $response->getResult();
        $file_path = $file->getFilePath();

        // Build download URL
        $api_token    = config('telegram.bot_token');
        $download_url = "https://api.telegram.org/file/bot$api_token/$file_path";

        // Download file content
        $file_content = @file_get_contents($download_url);

        if ($file_content === false) {
            throw new TelegramException('Failed to download file from Telegram');
        }

        // Generate unique filename
        $original_name = $document->getFileName() ?? 'document';
        $extension     = pathinfo($file_path, PATHINFO_EXTENSION);
        $filename      = $file->getFileUniqueId().'_'.$original_name;
        $message       = get_telegram_message($update);

        if ($extension && ! str_ends_with($filename, '.'.$extension)) {
            $filename .= '.'.$extension;
        }

        $storage_path = 'telegram/documents/'.date('Y/m/').$filename;
        $directory    = dirname($storage_path);

        // Create directory if not exists
        if (! Storage::directoryExists($directory)) {
            Storage::makeDirectory($directory);
        }

        // Save file content using put method
        Storage::put($storage_path, $file_content);

        return [
            'document' => $storage_path,
            'caption'  => $message->getCaption(),
        ];
    }

    /**
     * Download all photos and documents from update
     *
     *
     * @return array ['photos' => [...paths], 'documents' => [...paths]]
     *
     * @throws TelegramException
     */
    public function downloadAllFilesFromUpdate(Update $update): array
    {
        $result = [
            'photos'    => [],
            'documents' => [],
        ];

        // Download photo with caption if present
        if (is_telegram_has_photo($update)) {
            $photo_data = $this->downloadPhotosFromUpdate($update);

            if (! empty($photo_data)) {
                $result['photos'][] = $photo_data;
            }
        }

        // Download document with caption if present
        if (is_telegram_has_doc($update)) {
            $document_data = $this->downloadDocumentFromUpdate($update);

            if (! empty($document_data)) {
                $result['documents'][] = $document_data;
            }
        }

        return $result;
    }

    /**
     * Download and save voice message from update
     *
     *
     * @return string|null Saved file path or null
     *
     * @throws TelegramException
     */
    public function downloadVoiceFromUpdate(Update $update): ?string
    {
        $message = $update->getMessage() ?? $update->getEditedMessage();
        $voice   = $message?->getVoice();

        if ($voice === null) {
            return null;
        }

        $file_id = $voice->getFileId();

        // Get file info from Telegram
        $response = Request::getFile(['file_id' => $file_id]);

        if (! $response->isOk()) {
            throw new TelegramException('Failed to get voice file info: '.$response->getDescription());
        }

        /** @var File $file */
        $file      = $response->getResult();
        $file_path = $file->getFilePath();

        // Build download URL
        $api_token    = config('telegram.bot_token');
        $download_url = "https://api.telegram.org/file/bot$api_token/$file_path";

        // Download file content
        $file_content = @file_get_contents($download_url);

        if ($file_content === false) {
            throw new TelegramException('Failed to download voice message from Telegram');
        }

        // Generate unique filename (voice messages are typically OGG format)
        $extension    = pathinfo($file_path, PATHINFO_EXTENSION) ?: 'ogg';
        $filename     = $file->getFileUniqueId().'.'.$extension;
        $storage_path = 'telegram/voices/'.date('Y/m/').$filename;
        $directory    = dirname($storage_path);

        // Create directory if not exists
        if (! Storage::directoryExists($directory)) {
            Storage::makeDirectory($directory);
        }

        // Save file content
        Storage::put($storage_path, $file_content);

        return $storage_path;
    }

    /**
     * Send voice message to Telegram chat
     *
     * @param  int  $chat_id  Chat identifier
     * @param  string  $file_path  Path to voice file
     * @param  array  $params  Additional parameters
     */
    public function sendVoice(int $chat_id, string $file_path, array $params = []): ServerResponse
    {
        try {
            if (! Storage::exists($file_path)) {
                Log::channel('stack')->warning('Voice file not found', [
                    'path' => $file_path,
                ]);

                return Request::emptyResponse();
            }

            $data = [
                'chat_id' => $chat_id,
                'voice'   => Request::encodeFile(Storage::path($file_path)),
            ];

            if (! empty($params)) {
                $data = array_merge($data, $params);
            }

            $server_response = Request::sendVoice($data);

            if ($server_response->isOk()) {
                return $server_response;
            }

            Log::channel('stack')->error(
                'Telegram sendVoice error: '.$server_response->getDescription(),
                [
                    'error_code' => $server_response->getErrorCode(),
                    'chat_id'    => $chat_id,
                ]
            );

            return Request::emptyResponse();
        } catch (Exception $e) {
            Log::channel('stack')->error('Exception in sendVoice', [
                'error'     => $e->getMessage(),
                'chat_id'   => $chat_id,
                'file_path' => $file_path,
            ]);

            return Request::emptyResponse();
        }
    }

    /**
     * Download and save video message from update
     *
     *
     * @return array|null ['video' => path, 'caption' => caption] or null
     *
     * @throws TelegramException
     */
    public function downloadVideoFromUpdate(Update $update): ?array
    {
        $message = $update->getMessage() ?? $update->getEditedMessage();
        $video   = $message?->getVideo();

        if ($video === null) {
            return null;
        }

        $file_id = $video->getFileId();

        // Get file info from Telegram
        $response = Request::getFile(['file_id' => $file_id]);

        if (! $response->isOk()) {
            throw new TelegramException('Failed to get video file info: '.$response->getDescription());
        }

        /** @var File $file */
        $file      = $response->getResult();
        $file_path = $file->getFilePath();
        $file_name = $video->getFileName();

        // Build download URL
        $api_token    = config('telegram.bot_token');
        $download_url = "https://api.telegram.org/file/bot$api_token/$file_path";

        // Download file content
        $file_content = @file_get_contents($download_url);

        if ($file_content === false) {
            throw new TelegramException('Failed to download video message from Telegram');
        }

        // Generate unique filename
        $extension    = pathinfo($file_path, PATHINFO_EXTENSION) ?: 'mp4';
        $filename     = $file->getFileUniqueId().'_'.($file_name ?? '.'.$extension);
        $storage_path = 'telegram/videos/'.date('Y/m/').$filename;
        $directory    = dirname($storage_path);

        // Create directory if not exists
        if (! Storage::directoryExists($directory)) {
            Storage::makeDirectory($directory);
        }

        // Save file content
        Storage::put($storage_path, $file_content);

        return [
            'video'   => $storage_path,
            'caption' => $message->getCaption(),
        ];
    }

    /**
     * Send video message to Telegram chat with caption support
     *
     * @param  int  $chat_id  Chat identifier
     * @param  string  $file_path  Path to video file
     * @param  array  $params  Additional parameters (caption, parse_mode, etc.)
     */
    public function sendVideo(int $chat_id, string $file_path, array $params = []): ServerResponse
    {
        try {
            if (! Storage::exists($file_path)) {
                Log::channel('stack')->warning('Video file not found', [
                    'path' => $file_path,
                ]);

                return Request::emptyResponse();
            }

            $data = [
                'chat_id' => $chat_id,
                'video'   => Request::encodeFile(Storage::path($file_path)),
            ];

            if (! empty($params)) {
                $data = array_merge($data, $params);
            }

            $server_response = Request::sendVideo($data);

            if ($server_response->isOk()) {
                return $server_response;
            }

            Log::channel('stack')->error(
                'Telegram sendVideo error: '.$server_response->getDescription(),
                [
                    'error_code' => $server_response->getErrorCode(),
                    'chat_id'    => $chat_id,
                    'params'     => $params,
                ]
            );

            return Request::emptyResponse();
        } catch (Exception $e) {
            Log::channel('stack')->error('Exception in sendVideo', [
                'error'     => $e->getMessage(),
                'chat_id'   => $chat_id,
                'file_path' => $file_path,
            ]);

            return Request::emptyResponse();
        }
    }
}
