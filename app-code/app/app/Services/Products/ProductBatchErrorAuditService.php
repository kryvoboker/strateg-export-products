<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Products\Deletes\ProductDeleteBatch;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Updates\ProductUpdateBatch;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProductBatchErrorAuditService
{
    /**
     * @param ProductImportBatch $batch
     * @param array              $failed_details
     * @param Throwable|null     $exception
     * @param string|null        $reason
     *
     * @return null[]|string[]
     */
    public function syncImportBatchAudit(
        ProductImportBatch $batch,
        array              $failed_details = [],
        ?Throwable         $exception = null,
        ?string            $reason = null
    ): array {
        return $this->syncBatchAudit(
            batch_type    : 'imports',
            batch_id      : (int)$batch->id,
            source_type   : (string)$batch->source_type,
            source_name   : (string)$batch->source_name,
            failed_details: $failed_details,
            exception     : $exception,
            reason        : $reason,
        );
    }

    /**
     * @param ProductUpdateBatch $batch
     * @param array              $failed_details
     * @param Throwable|null     $exception
     * @param string|null        $reason
     *
     * @return null[]|string[]
     */
    public function syncUpdateBatchAudit(
        ProductUpdateBatch $batch,
        array              $failed_details = [],
        ?Throwable         $exception = null,
        ?string            $reason = null
    ): array {
        return $this->syncBatchAudit(
            batch_type    : 'updates',
            batch_id      : (int)$batch->id,
            source_type   : (string)$batch->source_type,
            source_name   : (string)$batch->source_name,
            failed_details: $failed_details,
            exception     : $exception,
            reason        : $reason,
        );
    }

    /**
     * @param ProductDeleteBatch $batch
     * @param array              $failed_details
     * @param Throwable|null     $exception
     * @param string|null        $reason
     *
     * @return null[]|string[]
     */
    public function syncDeleteBatchAudit(
        ProductDeleteBatch $batch,
        array              $failed_details = [],
        ?Throwable         $exception = null,
        ?string            $reason = null
    ): array {
        return $this->syncBatchAudit(
            batch_type    : 'deletes',
            batch_id      : (int)$batch->id,
            source_type   : (string)$batch->source_type,
            source_name   : (string)$batch->source_name,
            failed_details: $failed_details,
            exception     : $exception,
            reason        : $reason,
        );
    }

    /**
     * @param string         $batch_type
     * @param int            $batch_id
     * @param string         $source_type
     * @param string         $source_name
     * @param array          $failed_details
     * @param Throwable|null $exception
     * @param string|null    $reason
     *
     * @return null[]|string[]
     */
    private function syncBatchAudit(
        string     $batch_type,
        int        $batch_id,
        string     $source_type,
        string     $source_name,
        array      $failed_details = [],
        ?Throwable $exception = null,
        ?string    $reason = null
    ): array {
        $normalized_reason = $this->resolveLastError($failed_details, $exception, $reason);

        Log::channel('audit')->info('Product batch audit written', [
            'service'     => self::class,
            'batch_type'  => $batch_type,
            'batch_id'    => $batch_id,
            'failed_rows' => count($failed_details),
            'last_error'  => $normalized_reason,
            'exception'   => $exception,
            'source_type' => $source_type,
            'source_name' => $source_name,
            'stack'       => log_stack_trace(),
        ]);

        return [
            'last_error' => $normalized_reason,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $failed_details
     */
    private function resolveLastError(array $failed_details, ?Throwable $exception, ?string $reason): ?string
    {
        $normalized_reason = Str::limit(Str::trim((string)$reason), 10000);
        if ($normalized_reason !== '') {
            return $normalized_reason;
        }

        if ($exception !== null) {
            $exception_message = Str::limit(Str::trim($exception->getMessage()), 10000);

            return $exception_message !== '' ? $exception_message : 'Unhandled batch exception';
        }

        foreach ($failed_details as $failed_detail) {
            $message = Str::limit(Str::trim((string)Arr::get($failed_detail, 'message', '')), 10000);

            if ($message !== '') {
                return $message;
            }
        }

        return null;
    }
}
