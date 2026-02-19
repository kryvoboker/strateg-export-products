<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shops\ShopLanguages\Pages\Trait;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

trait CommonTrait
{
    /**
     * @throws Halt
     */
    private function validateShopLanguageBefore(array $data): void
    {
        $validator = Validator::make($data, [
            'code' => [
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

    public function getTitle(): string
    {
        return __('admin/shops/languages.navigation_label');
    }

    public function getHeading(): ?string
    {
        return __('admin/shops/languages.navigation_label');
    }
}
