<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Products\Deletes\ProductDeleteBatch;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Updates\ProductUpdateBatch;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProductBatchErrorAuditService
{
    private const string PUBLIC_DISK = 'public';

    /**
     * @param  array<int, array<string, mixed>>  $failed_details
     * @return array{last_error:?string,error_log_path:?string}
     */
    public function syncImportBatchAudit(
        ProductImportBatch $batch,
        array $failed_details = [],
        ?Throwable $exception = null,
        ?string $reason = null
    ): array {
        return $this->syncBatchAudit(
            batch_type: 'imports',
            batch_id: (int) $batch->id,
            source_type: (string) $batch->source_type,
            source_name: (string) $batch->source_name,
            existing_log_path: $batch->getErrorLogPath(),
            failed_details: $failed_details,
            exception: $exception,
            reason: $reason,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $failed_details
     * @return array{last_error:?string,error_log_path:?string}
     */
    public function syncUpdateBatchAudit(
        ProductUpdateBatch $batch,
        array $failed_details = [],
        ?Throwable $exception = null,
        ?string $reason = null
    ): array {
        return $this->syncBatchAudit(
            batch_type: 'updates',
            batch_id: (int) $batch->id,
            source_type: (string) $batch->source_type,
            source_name: (string) $batch->source_name,
            existing_log_path: $batch->getErrorLogPath(),
            failed_details: $failed_details,
            exception: $exception,
            reason: $reason,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $failed_details
     * @return array{last_error:?string,error_log_path:?string}
     */
    public function syncDeleteBatchAudit(
        ProductDeleteBatch $batch,
        array $failed_details = [],
        ?Throwable $exception = null,
        ?string $reason = null
    ): array {
        return $this->syncBatchAudit(
            batch_type: 'deletes',
            batch_id: (int) $batch->id,
            source_type: (string) $batch->source_type,
            source_name: (string) $batch->source_name,
            existing_log_path: $batch->getErrorLogPath(),
            failed_details: $failed_details,
            exception: $exception,
            reason: $reason,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $failed_details
     * @return array{last_error:?string,error_log_path:?string}
     */
    private function syncBatchAudit(
        string $batch_type,
        int $batch_id,
        string $source_type,
        string $source_name,
        ?string $existing_log_path,
        array $failed_details = [],
        ?Throwable $exception = null,
        ?string $reason = null
    ): array {
        $normalized_reason = $this->resolveLastError($failed_details, $exception, $reason);

        if ($normalized_reason === null && $failed_details === [] && $exception === null) {
            $this->deleteExistingAuditFile($existing_log_path);

            Log::channel('daily')->info('Product batch error audit cleared', [
                'service'            => self::class,
                'batch_type'         => $batch_type,
                'batch_id'           => $batch_id,
                'existing_log_path'  => $existing_log_path,
            ]);

            return [
                'last_error'     => null,
                'error_log_path' => null,
            ];
        }

        $audit_path = $this->buildAuditPath($batch_type, $batch_id);
        $audit_body = $this->buildAuditBody(
            batch_type: $batch_type,
            batch_id: $batch_id,
            source_type: $source_type,
            source_name: $source_name,
            failed_details: $failed_details,
            exception: $exception,
            reason: $normalized_reason,
        );

        try {
            Storage::disk(self::PUBLIC_DISK)->put($audit_path, $audit_body);

            if ($existing_log_path !== null && $existing_log_path !== $audit_path) {
                $this->deleteExistingAuditFile($existing_log_path);
            }

            Log::channel('daily')->info('Product batch error audit written', [
                'service'          => self::class,
                'batch_type'       => $batch_type,
                'batch_id'         => $batch_id,
                'error_log_path'   => $audit_path,
                'failed_rows'      => count($failed_details),
                'has_exception'    => $exception !== null,
                'last_error'       => $normalized_reason,
            ]);
        } catch (Throwable $throwable) {
            Log::channel('stack')->error('Failed to write product batch error audit', [
                'service'          => self::class,
                'batch_type'       => $batch_type,
                'batch_id'         => $batch_id,
                'error_log_path'   => $audit_path,
                'message'          => $throwable->getMessage(),
                'file'             => $throwable->getFile(),
                'line'             => $throwable->getLine(),
            ]);

            return [
                'last_error'     => $normalized_reason,
                'error_log_path' => null,
            ];
        }

        return [
            'last_error'     => $normalized_reason,
            'error_log_path' => $audit_path,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $failed_details
     */
    private function buildAuditBody(
        string $batch_type,
        int $batch_id,
        string $source_type,
        string $source_name,
        array $failed_details,
        ?Throwable $exception,
        ?string $reason
    ): string {
        $lines   = [];
        $lines[] = 'datetime: '.now()->toDateTimeString();
        $lines[] = 'batch_type: '.$batch_type;
        $lines[] = 'batch_id: '.$batch_id;
        $lines[] = 'source_type: '.Str::squish($source_type);
        $lines[] = 'source_name: '.Str::squish($source_name);

        if ($reason !== null) {
            $lines[] = 'last_error: '.$reason;
        }

        if ($exception !== null) {
            $lines[] = 'exception_class: '.$exception::class;
            $lines[] = 'exception_message: '.Str::squish($exception->getMessage());
            $lines[] = 'exception_file: '.$exception->getFile();
            $lines[] = 'exception_line: '.$exception->getLine();
        }

        $lines[] = 'failed_rows: '.count($failed_details);

        foreach ($failed_details as $index => $failed_detail) {
            $failed_row_number = $index + 1;
            $lines[]           = '';
            $lines[]           = sprintf(
                '#%d status=%s item_id=%s product_id=%s shop_id=%s sheet=%s row=%s source=%s message=%s',
                $failed_row_number,
                Str::squish((string) Arr::get($failed_detail, 'status', 'failed')),
                $this->normalizeScalarForLog(Arr::get($failed_detail, 'item_id')),
                $this->normalizeScalarForLog(Arr::get($failed_detail, 'product_id')),
                $this->normalizeScalarForLog(Arr::get($failed_detail, 'shop_id')),
                $this->normalizeScalarForLog(Arr::get($failed_detail, 'sheet')),
                $this->normalizeScalarForLog(Arr::get($failed_detail, 'row')),
                $this->normalizeScalarForLog(Arr::get($failed_detail, 'source_path')),
                $this->normalizeScalarForLog(Arr::get($failed_detail, 'message', 'Unknown error')),
            );
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function buildAuditPath(string $batch_type, int $batch_id): string
    {
        $now       = now();
        $directory = sprintf('logs/product-batches/%s/%s', $batch_type, $now->format('Y/m'));
        $file_name = sprintf('%s-batch-%d-errors-%s.log', Str::singular($batch_type), $batch_id, $now->format('Ymd_His_u'));

        return $directory.'/'.$file_name;
    }

    /**
     * @param  array<int, array<string, mixed>>  $failed_details
     */
    private function resolveLastError(array $failed_details, ?Throwable $exception, ?string $reason): ?string
    {
        $normalized_reason = Str::limit(Str::trim((string) $reason), 10000);
        if ($normalized_reason !== '') {
            return $normalized_reason;
        }

        if ($exception !== null) {
            $exception_message = Str::limit(Str::trim($exception->getMessage()), 10000);

            return $exception_message !== '' ? $exception_message : 'Unhandled batch exception';
        }

        foreach ($failed_details as $failed_detail) {
            $message = Str::limit(Str::trim((string) Arr::get($failed_detail, 'message', '')), 10000);

            if ($message !== '') {
                return $message;
            }
        }

        return null;
    }

    private function deleteExistingAuditFile(?string $existing_log_path): void
    {
        $normalized_existing_log_path = Str::trim((string) $existing_log_path);

        if ($normalized_existing_log_path === '') {
            return;
        }

        try {
            if (Storage::disk(self::PUBLIC_DISK)->exists($normalized_existing_log_path)) {
                Storage::disk(self::PUBLIC_DISK)->delete($normalized_existing_log_path);
            }
        } catch (Throwable $throwable) {
            Log::channel('stack')->warning('Failed to delete stale product batch error audit', [
                'service'          => self::class,
                'error_log_path'   => $normalized_existing_log_path,
                'message'          => $throwable->getMessage(),
                'file'             => $throwable->getFile(),
                'line'             => $throwable->getLine(),
            ]);
        }
    }

    private function normalizeScalarForLog(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        return Str::squish((string) $value);
    }
}
