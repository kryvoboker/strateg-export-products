<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shops\ShopLanguages\Pages;

use App\Filament\Resources\Shops\ShopLanguages\Pages\Trait\CommonTrait;
use App\Filament\Resources\Shops\ShopLanguages\ShopLanguageResource;
use App\Models\Shops\ShopLanguage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditShopLanguage extends EditRecord
{
    use CommonTrait;

    protected static string $resource = ShopLanguageResource::class;

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
     * @throws Halt
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof ShopLanguage && $record->code !== (string) ($data['code'] ?? '')) {
            // Validate only if the code has been changed
            $this->validateShopLanguageBefore($data);
        }

        return $data;
    }
}
