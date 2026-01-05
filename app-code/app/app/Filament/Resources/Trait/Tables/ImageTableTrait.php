<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Tables;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Support\Facades\Storage;

trait ImageTableTrait
{
    /**
     * @param array $params
     *
     * @return Column
     */
    protected static function getImageTableField(array $params = []): Column
    {
        return ImageColumn::make($params['field_name'] ?? 'image')
            ->label($params['label'] ?? __('admin/default.columns.image'))
            ->imageSize((int)($params['image_size'] ?? config('app.images.product.preview_in_list_in_admin.width')))
            ->circular($params['circular'] ?? false)
            ->checkFileExistence($params['check_file_existence'] ?? true)
            ->defaultImageUrl($params['default_image_url'] ?? Storage::url(config('app.images.product.no_image')))
            ->extraImgAttributes($params['extra_img_attributes'] ?? [
                'decoding' => 'async',
                'loading'  => 'lazy',
                'style'    => 'object-fit: contain;',
            ])
            ->getStateUsing($params['get_state_using_cb'] ?? null);
    }
}
