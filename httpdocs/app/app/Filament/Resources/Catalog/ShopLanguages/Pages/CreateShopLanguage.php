<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ShopLanguages\Pages;

use App\Filament\Resources\Catalog\ShopLanguages\ShopLanguageResource;
use App\Models\Shops\ShopLanguage;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateShopLanguage extends CreateRecord
{
    protected static string           $resource = ShopLanguageResource::class;
    public null|Model|ShopLanguage $record   = null;

    /**
     * @param array $data
     *
     * @return array
     * @throws Halt
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!isset($data['shop_id'])) {
            return $data;
        }

        $this->validateShopLanguageBeforeCreate($data);

        return $data;
    }

    /**
     * @param array $data
     *
     * @return void
     * @throws Halt
     */
    private function validateShopLanguageBeforeCreate(array $data): void
    {
        $validator = Validator::make($data, [
            'code'    => [
                Rule::unique('shop_languages', 'code')
                    ->where(function ($query) use ($data) {
                        return $query->where('shop_id', $data['shop_id']);
                    }),
            ],
        ]);

        if ($validator->fails()) {
            $error_message = $validator->errors()->first('code');

            Notification::make()
                ->title(__('admin/default.errors.title'))
                ->body($error_message)
                ->danger()
                ->send();

            $this->halt();
        }
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return __('admin/shops/languages.navigation_label');
    }

    /**
     * @return string|null
     */
    public function getHeading(): ?string
    {
        return __('admin/shops/languages.navigation_label');
    }
}
