<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Forms;

use App\Models\Settings\Language;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Illuminate\Database\Eloquent\Collection;

trait SlugFormTrait
{
    /**
     * @param  Collection<Language>  $active_languages
     */
    protected static function createSlugsFormTabs(Collection $active_languages): Tabs\Tab
    {
        $schema_fields = [];

        foreach ($active_languages as $language) {
            $schema_fields[] = TextInput::make("slugs.$language->id.name")
                ->hiddenLabel()
                ->prefix($language->code)
                ->maxLength(500)
                ->rules(['nullable', 'string', 'max:500']);
        }

        return Tabs\Tab::make(__('admin/default.tabs.slugs'))
            ->schema([
                Section::make(__('admin/default.sections.slugs'))
                    ->schema($schema_fields)
                    ->columnSpanFull(),
            ]);
    }
}
