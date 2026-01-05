<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Forms;

use App\Models\Settings\Language;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Illuminate\Database\Eloquent\Collection;

trait MetaTextFormTrait
{
    /**
     * @param Collection $active_languages
     *
     * @return array
     */
    protected static function createMetaTextsLanguageFormTabs(Collection $active_languages): array
    {
        $tabs = [];

        foreach ($active_languages as $language) {
            $tabs[] = Tabs\Tab::make($language->name)
                ->schema([
                    Hidden::make("descriptions.$language->id.language_id")
                        ->default($language->id),

                    /*TextInput::make("descriptions.$language->id.h1_title")
                        ->label(__('admin/default.labels.h1_title'))
                        ->maxLength(255)
                        ->rules(['nullable', 'string', 'max:255']),*/

                    TextInput::make("descriptions.$language->id.meta_title")
                        ->label(__('admin/default.labels.meta_title'))
                        ->maxLength(255)
                        ->rules(['nullable', 'string', 'max:255']),

                    Textarea::make("descriptions.$language->id.meta_description")
                        ->label(__('admin/default.labels.meta_description'))
                        ->rows(2)
                        ->rules(['nullable', 'string', 'max:255']),

                    Textarea::make("descriptions.$language->id.meta_keywords")
                        ->label(__('admin/default.labels.meta_keywords'))
                        ->rows(2)
                        ->rules(['nullable', 'string', 'max:255']),
                ])
                ->badge($language->code);
        }

        return $tabs;
    }

    /**
     * @param Collection $active_languages
     *
     * @return Tabs\Tab
     */
    protected static function createMetaTextsFormTabs(Collection $active_languages): Tabs\Tab
    {
        return Tabs\Tab::make(__('admin/default.tabs.meta_texts'))
            ->schema([
                Section::make(__('admin/default.sections.meta_texts'))
                    ->schema([
                        Tabs::make('language_tabs_meta_texts')
                            ->tabs(self::createMetaTextsLanguageFormTabs($active_languages))
                            ->activeTab(1)
                            ->contained(false)
                            ->persistTabInQueryString(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Create language tabs for translations
     *
     * @param Collection<Language> $active_languages
     *
     * @return array<Tabs\Tab>
     */
    protected static function processCreateTranslationsFormTabs(Collection $active_languages): array
    {
        $tabs            = [];
        $total_languages = $active_languages->count();

        foreach ($active_languages as $language) {
            $tabs[] = Tabs\Tab::make($language->name)
                ->schema([
                    Hidden::make("descriptions.$language->id.language_id")
                        ->default($language->id),

                    TextInput::make("descriptions.$language->id.name")
                        ->label(__('admin/default.labels.name'))
                        ->maxLength(255)
                        ->rules(['required', 'string', 'max:255'])
                        ->columnSpanFull()
                        ->required(),

                    RichEditor::make("descriptions.$language->id.description")
                        ->label(__('admin/default.labels.description'))
                        ->toolbarButtons([
                            ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript', 'link'],
                            ['h1', 'h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd', 'alignJustify', 'textColor'],
                            ['blockquote', 'bulletList', 'orderedList'],
                            ['table'],
                            ['undo', 'redo', 'clearFormatting'],
                        ])
                        ->rules(['nullable'])
                        ->columnSpanFull(),
                ])
                ->badge($language->code)
                ->columns(min($total_languages, 4));
        }

        return $tabs;
    }

    /**
     * Create translations tab with language tabs
     *
     * @param Collection<Language> $active_languages
     *
     * @return Tabs\Tab
     */
    protected static function createTranslationsFormTabs(Collection $active_languages): Tabs\Tab
    {
        return Tabs\Tab::make(__('admin/default.tabs.translations'))
            ->schema([
                Section::make(__('admin/default.sections.translations'))
                    ->schema([
                        Tabs::make('LanguageTabs')
                            ->tabs(self::processCreateTranslationsFormTabs($active_languages))
                            ->activeTab(1)
                            ->contained(false)
                            ->persistTabInQueryString(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
