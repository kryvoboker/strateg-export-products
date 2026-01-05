<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Forms;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Illuminate\Validation\Rule;

trait ImageFormTrait
{
    /**
     * @param array $params
     *
     * @return Field
     */
    protected static function getImageFormField(array $params = []): Field
    {
        return FileUpload::make($params['field_name'] ?? 'image')
            ->label($params['label'] ?? __('admin/default.labels.image'))  // store under other folder
            ->helperText($params['helper_text'] ?? null)
            ->image() // accept images only
            ->directory($params['directory'] ?? config('app.images.product.image_path'))
            ->maxSize((int)($params['max_size'] ?? config('app.images.product.upload.max_size_kb')))
            ->rules($params['rules'] ?? ['nullable', Rule::file()::types(['image/jpeg', 'image/png']), 'max:' . (int)config('app.images.product.upload.max_size_kb')])
            ->preserveFilenames() // not generate unique names
            ->imageEditor()
            ->imageEditorViewportWidth((int)($params['image_width'] ?? config('app.images.product.preview_in_page_in_admin.width')))
            ->imageEditorViewportHeight((int)($params['image_height'] ?? config('app.images.product.preview_in_page_in_admin.height')))
            ->imageEditorAspectRatios($params['image_aspect_ratios'] ?? [
                '1:1'  => '1:1',
                '4:3'  => '4:3',
                '16:9' => '16:9',
            ])
            ->nullable($params['nullable'] ?? true)
            ->default($params['default'] ?? null);
    }
}
