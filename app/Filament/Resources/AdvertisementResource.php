<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdvertisementResource\Pages;
use App\Models\Advertisement;
use App\Services\Image\AdvertisementImageService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class AdvertisementResource extends Resource
{
    protected static ?string $model = Advertisement::class;

    protected static ?string $navigationGroup = 'Marketing';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Publicités';

    protected static ?string $pluralModelLabel = 'Publicités';

    protected static ?string $modelLabel = 'Publicité';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(['default' => 1, 'xl' => 12])
                    ->schema([
                        // Colonne principale : Configuration (7 cols)
                        Forms\Components\Group::make([
                            Forms\Components\Section::make('Contenu de la publicité')
                                ->description('Renseignez les éléments visibles sur le carrousel mobile.')
                                ->icon('heroicon-o-megaphone')
                                ->schema([
                                    Forms\Components\TextInput::make('title')
                                        ->label('Titre principal')
                                        ->required()
                                        ->maxLength(255)
                                        ->placeholder('Ex: Offre Spéciale Déjeuner')
                                        ->live(onBlur: true)
                                        ->columnSpanFull(),

                                    Forms\Components\TextInput::make('subtitle')
                                        ->label('Badge promotionnel')
                                        ->maxLength(40)
                                        ->placeholder('Ex: NOUVEAU, -20% CE SOIR...')
                                        ->live(onBlur: true),

                                    Forms\Components\TextInput::make('link_url')
                                        ->label('Lien de redirection (Optionnel)')
                                        ->url()
                                        ->maxLength(255)
                                        ->placeholder('https://moncommerce.com/promo')
                                        ->prefixIcon('heroicon-m-link')
                                        ->live(onBlur: true),

                                    Forms\Components\Textarea::make('description')
                                        ->label('Description')
                                        ->rows(3)
                                        ->placeholder('Ex: Profitez d\'avantages exclusifs dès maintenant en magasin...')
                                        ->columnSpanFull()
                                        ->live(onBlur: true),
                                ])
                                ->columns(2),

                            Forms\Components\Section::make('Visuel & Optimisation')
                                ->description('L\'image est automatiquement recadrée et convertie au format WebP optimisé.')
                                ->icon('heroicon-o-photo')
                                ->schema([
                                    Forms\Components\FileUpload::make('image_path')
                                        ->label('Image de bannière')
                                        ->disk('public')
                                        ->directory('advertisements')
                                        ->image()
                                        ->imageEditor()
                                        ->imageEditorAspectRatios([
                                            '16:9',
                                            '4:3',
                                            '1:1',
                                        ])
                                        ->imageResizeMode('cover')
                                        ->imageResizeTargetWidth(1200)
                                        ->imageResizeTargetHeight(675)
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                        ->maxSize(5120)
                                        ->saveUploadedFileUsing(function ($file) {
                                            return app(AdvertisementImageService::class)->optimizeAndStore($file);
                                        })
                                        ->live()
                                        ->helperText('Format recommandé : 16:9 (1200×675 px). Taille max : 5 Mo.')
                                        ->columnSpanFull(),
                                ]),

                            Forms\Components\Section::make('Diffusion & Programmation')
                                ->description('Gérez la période de visibilité et l\'ordre de passage.')
                                ->icon('heroicon-o-calendar-days')
                                ->schema([
                                    Forms\Components\Toggle::make('is_active')
                                        ->label('Publicité active')
                                        ->helperText('Activez ou désactivez l\'affichage dans l\'application.')
                                        ->default(true)
                                        ->live(),

                                    Forms\Components\TextInput::make('order')
                                        ->label('Ordre d\'affichage')
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0)
                                        ->helperText('0 = affiché en premier dans le carrousel.')
                                        ->live(onBlur: true),

                                    Forms\Components\DateTimePicker::make('starts_at')
                                        ->label('Date de début')
                                        ->seconds(false)
                                        ->native(false)
                                        ->helperText('Laissez vide pour afficher immédiatement.')
                                        ->live(),

                                    Forms\Components\DateTimePicker::make('ends_at')
                                        ->label('Date de fin')
                                        ->seconds(false)
                                        ->native(false)
                                        ->after('starts_at')
                                        ->helperText('Laissez vide pour une diffusion continue.')
                                        ->live(),
                                ])
                                ->columns(2),
                        ])
                        ->columnSpan(['default' => 12, 'xl' => 7]),

                        // Colonne latérale : Aperçu direct & Astuces (5 cols)
                        Forms\Components\Group::make([
                            Forms\Components\Section::make('Aperçu en direct')
                                ->description('Simulation du rendu sur l\'application mobile.')
                                ->icon('heroicon-o-device-phone-mobile')
                                ->schema([
                                    Forms\Components\ViewField::make('preview')
                                        ->label('')
                                        ->view('filament.forms.components.advertisement-preview')
                                        ->viewData(fn (Forms\Get $get): array => [
                                            'title' => $get('title'),
                                            'subtitle' => $get('subtitle'),
                                            'description' => $get('description'),
                                            'link_url' => $get('link_url'),
                                            'is_active' => $get('is_active'),
                                            'starts_at' => $get('starts_at'),
                                            'ends_at' => $get('ends_at'),
                                            'image_path' => $get('image_path'),
                                        ])
                                        ->dehydrated(false),
                                ]),

                            Forms\Components\Section::make('Bonnes pratiques')
                                ->icon('heroicon-o-light-bulb')
                                ->collapsed()
                                ->schema([
                                    Forms\Components\Placeholder::make('tips')
                                        ->label('')
                                        ->content(new HtmlString('
                                            <div class="text-xs text-slate-600 dark:text-slate-400 space-y-2">
                                                <p>• <strong>Visuel 16:9 :</strong> Utilisez une image horizontale pour épouser fidèlement le bandeau mobile.</p>
                                                <p>• <strong>Optimisation automatique :</strong> Le serveur compresse et convertit l\'image en WebP léger pour un affichage instantané sans ralentir le client.</p>
                                                <p>• <strong>Accroche percutante :</strong> Un titre concis et un badge court maximisent l\'impact visuel.</p>
                                            </div>
                                        ')),
                                ]),
                        ])
                        ->columnSpan(['default' => 12, 'xl' => 5]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_url')
                    ->label('Visuel')
                    ->width(82)
                    ->height(46)
                    ->extraImgAttributes([
                        'class' => 'rounded-lg object-cover shadow-xs border border-slate-200 dark:border-slate-800',
                    ])
                    ->defaultImageUrl(fn (?Advertisement $record): ?string => \App\Support\AvatarHelper::forAdvertisement($record)),

                Tables\Columns\TextColumn::make('title')
                    ->label('Publicité')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Advertisement $record): ?string => $record->subtitle ? "Badge : {$record->subtitle}" : null),

                Tables\Columns\TextColumn::make('status')
                    ->label('État')
                    ->badge()
                    ->state(function (Advertisement $record): string {
                        if (! $record->is_active) {
                            return 'Désactivé';
                        }
                        if ($record->starts_at && $record->starts_at->isFuture()) {
                            return 'Programmé';
                        }
                        if ($record->ends_at && $record->ends_at->isPast()) {
                            return 'Expiré';
                        }
                        return 'En diffusion';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'En diffusion' => 'success',
                        'Programmé' => 'info',
                        'Expiré' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('link_url')
                    ->label('Lien')
                    ->limit(24)
                    ->url(fn ($record) => $record->link_url, true)
                    ->color('primary')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('period')
                    ->label('Période')
                    ->state(function (Advertisement $record): string {
                        if (! $record->starts_at && ! $record->ends_at) {
                            return 'Permanente';
                        }
                        $start = $record->starts_at ? $record->starts_at->format('d/m/y') : 'Immédiat';
                        $end = $record->ends_at ? $record->ends_at->format('d/m/y') : 'Illimité';
                        return "{$start} → {$end}";
                    })
                    ->description(function (Advertisement $record): ?string {
                        if ($record->starts_at || $record->ends_at) {
                            $times = [];
                            if ($record->starts_at) {
                                $times[] = 'Dès ' . $record->starts_at->format('H:i');
                            }
                            if ($record->ends_at) {
                                $times[] = 'Jusqu\'à ' . $record->ends_at->format('H:i');
                            }
                            return implode(' • ', $times);
                        }
                        return null;
                    }),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Actif')
                    ->sortable(),

                Tables\Columns\TextColumn::make('order')
                    ->label('Ordre')
                    ->numeric()
                    ->sortable()
                    ->alignCenter(),
            ])
            ->defaultSort('order', 'asc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut actif'),
                Tables\Filters\Filter::make('active_now')
                    ->label('En cours de diffusion')
                    ->query(fn (Builder $query) => $query->active()),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('Aperçu')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->color('gray')
                    ->modalHeading(fn (Advertisement $record) => "Aperçu mobile : {$record->title}")
                    ->modalContent(fn (Advertisement $record) => view('filament.forms.components.advertisement-preview-modal', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer'),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucune bannière publicitaire')
            ->emptyStateDescription('Configurez les bannières visibles sur l\'écran d\'accueil de l\'application.')
            ->emptyStateIcon('heroicon-o-megaphone');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdvertisements::route('/'),
            'create' => Pages\CreateAdvertisement::route('/create'),
            'edit' => Pages\EditAdvertisement::route('/{record}/edit'),
        ];
    }
}
