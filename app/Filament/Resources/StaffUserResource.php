<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffUserResource\Pages;
use App\Models\StaffUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StaffUserResource extends Resource
{
    protected static ?string $model = StaffUser::class;

    protected static ?string $navigationGroup = 'Marchands';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Personnel';

    protected static ?string $pluralModelLabel = 'Personnel Commerces';

    protected static ?string $modelLabel = 'Membre du Personnel';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identité & Rôle du Collaborateur')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('restaurant_id')
                                    ->label('Établissement Associé')
                                    ->relationship('restaurant', 'name')
                                    ->getOptionLabelFromRecordUsing(fn ($record): string => (string) ($record->name ?: "Établissement #{$record->id} (" . ($record->email ?: 'Sans nom') . ")"))
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\TextInput::make('name')
                                    ->label('Nom & Prénom')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\Select::make('role')
                                    ->label('Rôle & Permissions')
                                    ->options([
                                        'manager' => 'Manager / Gérant',
                                        'operator' => 'Opérateur / Serveur',
                                    ])
                                    ->default('operator')
                                    ->required(),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('email')
                                    ->label('Adresse E-mail')
                                    ->email()
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('phone')
                                    ->label('Téléphone')
                                    ->tel()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('password')
                                    ->label('Mot de passe')
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->required(fn (string $context): bool => $context === 'create')
                                    ->helperText('Laissez vide pour conserver l\'actuel en modification'),
                            ]),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Compte Actif (peut scanner et attribuer des points)')
                            ->default(true)
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Collaborateur')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('restaurant.name')
                    ->label('Établissement')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Contact')
                    ->description(fn (StaffUser $record): ?string => $record->phone)
                    ->searchable(['email', 'phone'])
                    ->copyable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'manager' => 'primary',
                        default => 'info',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'manager' => 'Manager',
                        'operator' => 'Opérateur',
                        default => ucfirst((string) $state),
                    })
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ajouté le')
                    ->dateTime('d/m/Y')
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('restaurant_id')
                    ->label('Établissement')
                    ->options(fn (): array => \App\Models\Restaurant::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn ($r) => [$r->id => (string) ($r->name ?: "Établissement #{$r->id} (" . ($r->email ?: 'Sans nom') . ")")])
                        ->all()
                    )
                    ->searchable(),

                Tables\Filters\SelectFilter::make('role')
                    ->label('Rôle')
                    ->options([
                        'manager' => 'Manager',
                        'operator' => 'Opérateur',
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
            ->emptyStateHeading('Aucun personnel enregistré')
            ->emptyStateDescription('Les collaborateurs des établissements autorisés à valider les tampons s\'afficheront ici.')
            ->emptyStateIcon('heroicon-o-user-group');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaffUsers::route('/'),
            'create' => Pages\CreateStaffUser::route('/create'),
            'edit' => Pages\EditStaffUser::route('/{record}/edit'),
        ];
    }
}
