<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ShopLanguages\Pages;

use App\Filament\Resources\Catalog\ShopLanguages\ShopLanguageResource;
use App\Models\Shops\ShopLanguage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

class EditShopLanguage extends EditRecord
{
    protected static string                   $resource = ShopLanguageResource::class;
    #[Locked]
    public string|int|null|Model|ShopLanguage $record;

    /**
     * @return array|Action[]|ActionGroup[]
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param array $data
     *
     * @return array
     * @throws Halt
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->code != $data['code']) {
            // Validate only if the code has been changed
            $this->validateShopLanguageBeforeCreate($data);
        }

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
            'code' => [
                Rule::unique('shop_languages', 'code')
                    ->where(function (Builder $query) use ($data) {
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
