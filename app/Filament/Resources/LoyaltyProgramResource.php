<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoyaltyProgramResource\Pages;
use App\Models\LoyaltyProgram;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LoyaltyProgramResource extends Resource
{
    protected static ?string $model = LoyaltyProgram::class;

    protected static ?string $navigationGroup = 'Marchands';

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Programmes';

    protected static ?string $pluralModelLabel = 'Programmes de Fidélité';

    protected static ?string $modelLabel = 'Programme de Fidélité';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Configuration du Programme')
                    ->description('Règles de fonctionnement du programme pour les clients.')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('restaurant_id')
                                    ->label('Établissement')
                                    ->relationship('restaurant', 'name')
                                    ->getOptionLabelFromRecordUsing(fn ($record): string => (string) ($record->name ?: "Établissement #{$record->id} (" . ($record->email ?: 'Sans nom') . ")"))
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\TextInput::make('name')
                                    ->label('Nom du Programme')
                                    ->placeholder('Ex: Carte Fidélité 10 Tampons')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\Select::make('type')
                                    ->label('Mécanisme de Fidélité')
                                    ->options([
                                        'stamps' => 'Tampons / Passages',
                                        'cashback' => 'Cashback en FCFA',
                                        'points' => 'Système de Points',
                                    ])
                                    ->required(),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Toggle::make('is_active')
                                    ->label('Programme Actif')
                                    ->default(true)
                                    ->required(),

                                Forms\Components\Toggle::make('loops')
                                    ->label('Cycle Répétitif (Recommence une fois complété)')
                                    ->default(true)
                                    ->required(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Programme')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('restaurant.name')
                    ->label('Établissement')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Mécanisme')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'stamps' => 'info',
                        'cashback' => 'warning',
                        default => 'primary',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'stamps' => 'Tampons',
                        'cashback' => 'Cashback',
                        'points' => 'Points',
                        default => ucfirst((string) $state),
                    })
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\IconColumn::make('loops')
                    ->label('Boucle')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'stamps' => 'Tampons',
                        'cashback' => 'Cashback',
                        'points' => 'Points',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut Actif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucun programme de fidélité')
            ->emptyStateDescription('Les programmes configurés par les établissements apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-gift');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoyaltyPrograms::route('/'),
            'create' => Pages\CreateLoyaltyProgram::route('/create'),
            'edit' => Pages\EditLoyaltyProgram::route('/{record}/edit'),
        ];
    }
}
