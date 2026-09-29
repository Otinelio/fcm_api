<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SuperAdminResource\Pages;
use App\Models\SuperAdmin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SuperAdminResource extends Resource
{
    protected static ?string $model = SuperAdmin::class;

    protected static ?string $navigationGroup = 'Système';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Admins';

    protected static ?string $pluralModelLabel = 'Super Administrateurs';

    protected static ?string $modelLabel = 'Super Administrateur';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identifiant & Sécurité Administrateur')
                    ->description('Accès avec privilèges élevés sur l\'ensemble de la plateforme.')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nom Complet')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('email')
                                    ->label('Adresse E-mail')
                                    ->email()
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\Select::make('role')
                                    ->label('Privilège')
                                    ->options([
                                        'super_admin' => 'Super Administrateur (Tous droits)',
                                        'admin' => 'Administrateur Standard',
                                    ])
                                    ->default('super_admin')
                                    ->required(),
                            ]),

                        Forms\Components\TextInput::make('password')
                            ->label('Mot de Passe')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->helperText('Laissez vide pour conserver le mot de passe actuel en modification')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Administrateur')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('E-mail copié'),

                Tables\Columns\TextColumn::make('role')
                    ->label('Accès')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        default => 'primary',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'super_admin' => 'Super Admin',
                        'admin' => 'Admin',
                        default => ucfirst((string) $state),
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                // Aucune suppression en masse autorisée pour les comptes administrateurs
            ])
            ->emptyStateHeading('Aucun administrateur configuré')
            ->emptyStateDescription('Les comptes administrateurs système s\'afficheront ici.')
            ->emptyStateIcon('heroicon-o-shield-check');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuperAdmins::route('/'),
            'create' => Pages\CreateSuperAdmin::route('/create'),
            'edit' => Pages\EditSuperAdmin::route('/{record}/edit'),
        ];
    }
}
