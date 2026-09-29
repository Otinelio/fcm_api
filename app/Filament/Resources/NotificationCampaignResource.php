<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationCampaignResource\Pages;
use App\Filament\Resources\NotificationCampaignResource\RelationManagers;
use App\Models\NotificationCampaign;
use App\Support\AvatarHelper;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class NotificationCampaignResource extends Resource
{
    protected static ?string $model = NotificationCampaign::class;

    protected static ?string $navigationGroup = 'Marketing';

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = 'Campagnes';

    protected static ?string $pluralModelLabel = 'Campagnes Push FCM';

    protected static ?string $modelLabel = 'Campagne Push FCM';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'restaurant:id,name,logo_url,city',
                'logs',
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Contenu de la Notification Push')
                    ->description('Titre et message qui s\'afficheront sur l\'écran du smartphone des clients.')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('restaurant_id')
                                    ->label('Établissement Émetteur')
                                    ->relationship('restaurant', 'name')
                                    ->getOptionLabelFromRecordUsing(fn ($record): string => (string) ($record->name ?: "Établissement #{$record->id} (".($record->email ?: 'Sans nom').')'))
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->helperText(function ($state): ?string {
                                        if ($state) {
                                            $r = \App\Models\Restaurant::find($state);
                                            if ($r && $r->isFcmSuspended()) {
                                                return '⚠️ Attention : Le service Push FCM de cet établissement est actuellement suspendu.';
                                            }
                                        }

                                        return null;
                                    }),

                                Forms\Components\TextInput::make('title')
                                    ->label('Titre de la Notification')
                                    ->placeholder('Ex: Votre dessert offert ce soir !')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\Select::make('type')
                                    ->label('Catégorie de la Campagne')
                                    ->options([
                                        'promotion' => 'Offre Spéciale / Promo',
                                        'information' => 'Actualité & Événement',
                                        'reminder' => 'Rappel de Fidélité',
                                        'reward' => 'Récompense Débloquée',
                                    ])
                                    ->default('promotion')
                                    ->required(),
                            ]),

                        Forms\Components\Textarea::make('message')
                            ->label('Corps du Message Push')
                            ->rows(3)
                            ->placeholder('Rédigez un message clair et incitatif qui sera lu par le client...')
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\FileUpload::make('image_url')
                            ->label('Visuel Push / Image d\'illustration (Optionnel)')
                            ->image()
                            ->disk('public')
                            ->directory('campaigns')
                            ->formatStateUsing(function ($state) {
                                if (! $state) {
                                    return null;
                                }
                                if (str_contains($state, '/storage/')) {
                                    $parts = explode('/storage/', $state, 2);

                                    return ltrim($parts[1] ?? '', '/');
                                }

                                return ltrim($state, '/');
                            })
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Diffusion & Programmation')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('kind')
                                    ->label('Mode d\'envoi')
                                    ->options([
                                        'manual' => 'Envoi Direct / Planifié',
                                        'automated' => 'Scénario Automatisé',
                                    ])
                                    ->default('manual')
                                    ->required(),

                                Forms\Components\Select::make('status')
                                    ->label('Statut de la Campagne')
                                    ->options([
                                        'draft' => 'Brouillon',
                                        'scheduled' => 'Programmée',
                                        'sent' => 'Envoyée avec succès',
                                        'failed' => 'Échouée',
                                    ])
                                    ->default('draft')
                                    ->required()
                                    ->rules([
                                        fn (Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                            if (in_array($value, ['scheduled', 'sent'], true)) {
                                                $restaurantId = $get('restaurant_id');
                                                if ($restaurantId) {
                                                    $restaurant = \App\Models\Restaurant::find($restaurantId);
                                                    if ($restaurant && $restaurant->isFcmSuspended()) {
                                                        $fail("Le service Push FCM de cet établissement est suspendu. Impossible de programmer ou d'envoyer la campagne.");
                                                    }
                                                }
                                            }
                                        },
                                    ]),

                                Forms\Components\DateTimePicker::make('scheduled_at')
                                    ->label('Date & Heure programmée'),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\DateTimePicker::make('sent_at')
                                    ->label('Date d\'envoi effectif')
                                    ->disabled(),

                                Forms\Components\DateTimePicker::make('archived_at')
                                    ->label('Date d\'archivage'),
                            ]),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\ViewEntry::make('campaign_stats_and_preview')
                    ->view('filament.resources.notification-campaign.components.campaign-stats-and-preview')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_url')
                    ->label('Visuel')
                    ->width(48)
                    ->height(48)
                    ->extraImgAttributes(['class' => 'rounded-lg object-cover shadow-xs border border-slate-200 dark:border-slate-800'])
                    ->defaultImageUrl(fn (?NotificationCampaign $record): ?string => AvatarHelper::forCampaign($record)),

                Tables\Columns\TextColumn::make('restaurant.name')
                    ->label('Établissement')
                    ->weight('bold')
                    ->description(fn (NotificationCampaign $record): ?string => $record->restaurant?->city ?? 'Commerce partenaire')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Contenu & Message')
                    ->weight('semibold')
                    ->description(fn (NotificationCampaign $record): ?string => $record->message ? Str::limit($record->message, 50) : null)
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'promotion' => 'Offre Spéciale',
                        'information' => 'Actualité',
                        'reminder' => 'Rappel',
                        'reward' => 'Récompense',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'promotion' => 'primary',
                        'information' => 'info',
                        'reminder' => 'warning',
                        'reward' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('date_timeline')
                    ->label('Envoi / Programmation')
                    ->getStateUsing(function (NotificationCampaign $record): string {
                        if ($record->status === 'sent' && $record->sent_at) {
                            return 'Envoyée le '.$record->sent_at->format('d/m/Y H:i');
                        }
                        if ($record->status === 'scheduled' && $record->scheduled_at) {
                            return 'Prévue le '.$record->scheduled_at->format('d/m/Y H:i');
                        }
                        if ($record->created_at) {
                            return 'Créée le '.$record->created_at->format('d/m/Y');
                        }

                        return '—';
                    })
                    ->description(fn (NotificationCampaign $record): ?string => match ($record->status) {
                        'sent' => 'Diffusion confirmée',
                        'scheduled' => 'En file d\'attente',
                        'draft' => 'Non diffusée',
                        default => null,
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('sent_at', $direction)->orderBy('scheduled_at', $direction);
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent' => 'Envoyée',
                        'scheduled' => 'Programmée',
                        'draft' => 'Brouillon',
                        'failed' => 'Échouée',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'scheduled' => 'info',
                        'draft' => 'gray',
                        'failed' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('recipients_count')
                    ->label('Destinataires')
                    ->alignCenter()
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-o-users')
                    ->getStateUsing(fn (NotificationCampaign $record): int => $record->recipients_count),

                Tables\Columns\TextColumn::make('delivered_count')
                    ->label('Envoyés (FCM)')
                    ->alignCenter()
                    ->badge()
                    ->color('success')
                    ->icon('heroicon-o-check')
                    ->getStateUsing(fn (NotificationCampaign $record): int => $record->delivered_count),

                Tables\Columns\TextColumn::make('failed_count')
                    ->label('Échecs')
                    ->alignCenter()
                    ->badge()
                    ->getStateUsing(fn (NotificationCampaign $record): string => $record->failed_count > 0 ? (string) $record->failed_count : '—')
                    ->color(fn (string $state): string => $state !== '—' ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('read_count')
                    ->label('Vus / Lus')
                    ->alignCenter()
                    ->badge()
                    ->color('sky')
                    ->icon('heroicon-o-eye')
                    ->getStateUsing(fn (NotificationCampaign $record): int => $record->read_count),

                Tables\Columns\TextColumn::make('read_rate')
                    ->label('Taux Lecture')
                    ->alignCenter()
                    ->badge()
                    ->getStateUsing(function (NotificationCampaign $record): string {
                        $rate = $record->read_rate;

                        return $rate !== null ? "{$rate}%" : '—';
                    })
                    ->color(function (string $state): string {
                        if ($state === '—') {
                            return 'gray';
                        }
                        $num = (float) rtrim($state, '%');
                        if ($num >= 50) {
                            return 'success';
                        }
                        if ($num >= 20) {
                            return 'primary';
                        }

                        return 'warning';
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'sent' => 'Envoyée avec succès',
                        'scheduled' => 'Programmée',
                        'draft' => 'Brouillon',
                        'failed' => 'Échouée',
                    ]),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Catégorie')
                    ->options([
                        'promotion' => 'Offre Spéciale',
                        'information' => 'Actualité & Événement',
                        'reminder' => 'Rappel de Fidélité',
                        'reward' => 'Récompense Débloquée',
                    ]),

                Tables\Filters\SelectFilter::make('restaurant_id')
                    ->label('Établissement')
                    ->options(fn (): array => \App\Models\Restaurant::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn ($r) => [$r->id => (string) ($r->name ?: "Établissement #{$r->id} (".($r->email ?: 'Sans nom').')')])
                        ->all()
                    )
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Détails & Résultats')
                    ->icon('heroicon-o-eye')
                    ->color('primary'),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucune campagne push enregistrée')
            ->emptyStateDescription('Les notifications push planifiées ou envoyées aux clients apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-paper-airplane');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationCampaigns::route('/'),
            'create' => Pages\CreateNotificationCampaign::route('/create'),
            'view' => Pages\ViewNotificationCampaign::route('/{record}'),
            'edit' => Pages\EditNotificationCampaign::route('/{record}/edit'),
        ];
    }
}
