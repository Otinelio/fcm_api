<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $navigationGroup = 'Clients';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Clients';

    protected static ?string $pluralModelLabel = 'Clients Mobiles';

    protected static ?string $modelLabel = 'Client Mobile';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identité & Profil Client')
                    ->description('Coordonnées du client et statut du compte mobile.')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('first_name')
                                    ->label('Prénom')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('last_name')
                                    ->label('Nom')
                                    ->maxLength(255),

                                Forms\Components\DatePicker::make('birthdate')
                                    ->label('Date de Naissance'),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('phone')
                                    ->label('Téléphone')
                                    ->tel()
                                    ->required()
                                    ->placeholder('+229XXXXXXXX')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('email')
                                    ->label('Adresse E-mail')
                                    ->email()
                                    ->maxLength(255),

                                Forms\Components\DateTimePicker::make('phone_verified_at')
                                    ->label('Vérification Numéro')
                                    ->helperText('Date de validation OTP'),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('city')
                                    ->label('Ville')
                                    ->placeholder('Cotonou')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('country')
                                    ->label('Pays')
                                    ->placeholder('Bénin')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('avatar_url')
                                    ->label('URL Photo Profil')
                                    ->url()
                                    ->placeholder('https://...'),
                            ]),
                    ]),

                Forms\Components\Section::make('Connexion & Sécurité')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('oauth_provider')
                                    ->label('Méthode de Connexion')
                                    ->disabled()
                                    ->placeholder('Mot de passe standard'),

                                Forms\Components\TextInput::make('oauth_id')
                                    ->label('ID OAuth')
                                    ->disabled()
                                    ->visible(fn ($record) => filled($record?->oauth_id)),

                                Forms\Components\TextInput::make('password')
                                    ->label('Nouveau Mot de Passe')
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->required(fn (string $context): bool => $context === 'create')
                                    ->helperText('Laissez vide pour conserver l\'actuel'),
                            ]),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn (?Client $record): ?string => \App\Support\AvatarHelper::forClient($record)),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Client')
                    ->description(fn (Client $record): ?string => $record->email)
                    ->searchable(['first_name', 'last_name', 'email'])
                    ->sortable(['first_name'])
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Numéro copié')
                    ->description(fn (Client $record): string => $record->phone_verified_at ? '✓ Vérifié' : '⚠ Non vérifié')
                    ->icon(fn (Client $record): ?string => $record->phone_verified_at ? 'heroicon-m-check-badge' : 'heroicon-m-exclamation-triangle')
                    ->iconColor(fn (Client $record): string => $record->phone_verified_at ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('city')
                    ->label('Localisation')
                    ->formatStateUsing(fn ($state, Client $record) => $state ? "{$state}" . ($record->country ? ", {$record->country}" : '') : '—')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('loyalty_cards_count')
                    ->label('Cartes')
                    ->counts('loyaltyCards')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('oauth_provider')
                    ->label('Connexion')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : 'Classique')
                    ->color(fn ($state) => $state ? 'info' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Inscrit')
                    ->since()
                    ->sortable()
                    ->color('gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('phone_verified')
                    ->label('Téléphone Vérifié')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('phone_verified_at'),
                        false: fn ($query) => $query->whereNull('phone_verified_at'),
                    ),

                Tables\Filters\SelectFilter::make('oauth_provider')
                    ->label('Méthode de connexion')
                    ->options([
                        'google' => 'Google',
                        'apple' => 'Apple',
                    ])
                    ->query(function ($query, array $data) {
                        if ($data['value'] === null) return $query;
                        return $query->where('oauth_provider', $data['value']);
                    }),

                Tables\Filters\Filter::make('has_cards')
                    ->label('Avec carte(s) de fidélité')
                    ->query(fn ($query) => $query->has('loyaltyCards'))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucun client enregistré')
            ->emptyStateDescription('Les utilisateurs de l\'application mobile apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-users');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'view' => Pages\ViewClient::route('/{record}'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
