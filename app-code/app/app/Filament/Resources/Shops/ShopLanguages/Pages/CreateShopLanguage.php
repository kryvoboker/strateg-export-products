<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shops\ShopLanguages\Pages;

use App\Filament\Resources\Shops\ShopLanguages\Pages\Trait\CommonTrait;
use App\Filament\Resources\Shops\ShopLanguages\ShopLanguageResource;
use App\Models\Shops\ShopLanguage;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateShopLanguage extends CreateRecord
{
    use CommonTrait;

    protected static string        $resource = ShopLanguageResource::class;
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

        $this->validateShopLanguageBefore($data);

        return $data;
    }
}
