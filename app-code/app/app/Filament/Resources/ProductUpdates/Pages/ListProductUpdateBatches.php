<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\Pages;

use App\Filament\Resources\ProductUpdates\ProductUpdateBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ListProductUpdateBatches extends ListRecords
{
    protected static string $resource = ProductUpdateBatchResource::class;

    /**
     * @param  array<string, mixed>  $update_instructions
     * @return array<string, mixed>
     */
    public static function sanitizeImmutableProductFieldsFromUpdateInstructions(array $update_instructions): array
    {
        if ($update_instructions === []) {
            return [];
        }

        $immutable_fields = ['product_id', 'model', 'ean'];
        $normalized       = [];

        foreach ($update_instructions as $sheet_name => $sheet_payload) {
            if (! is_array($sheet_payload)) {
                continue;
            }

            $sheet_fields = Arr::get($sheet_payload, 'fields');
            if (! is_array($sheet_fields) || $sheet_fields === []) {
                continue;
            }

            foreach ($sheet_fields as $field_name => $field_payload) {
                $normalized_field_name = Str::snake(Str::squish((string) $field_name));
                if (in_array($normalized_field_name, $immutable_fields, true)) {
                    unset($sheet_fields[$field_name]);
                }
            }

            if ($sheet_fields === []) {
                continue;
            }

            $normalized[$sheet_name] = [
                ...$sheet_payload,
                'fields' => $sheet_fields,
            ];
        }

        return $normalized;
    }

    protected function getTablePollingInterval(): ?string
    {
        return '5s';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('admin/product_updates/batches.actions.create')),
        ];
    }

    public function getTitle(): string
    {
        return __('admin/product_updates/batches.navigation_label');
    }

    public function getHeading(): ?string
    {
        return __('admin/product_updates/batches.navigation_label');
    }
}
