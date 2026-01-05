<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ShopLanguages\Pages;

use App\Filament\Resources\Catalog\ShopLanguages\Pages\Trait\CommonTrait;
use App\Filament\Resources\Catalog\ShopLanguages\ShopLanguageResource;
use App\Models\Shops\ShopLanguage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditShopLanguage extends EditRecord
{
    use CommonTrait;

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
            $this->validateShopLanguageBefore($data);
        }

        return $data;
    }
}
