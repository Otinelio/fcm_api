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

                Tables\Columns\IconColumn::make('auth_status')
                    ->label('Accès')
                    ->getStateUsing(fn (StaffUser $record): bool => ! \App\Services\Auth\LoginThrottleService::isLocked('staff', $record->email))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-open')
                    ->falseIcon('heroicon-s-lock-closed')
                    ->trueColor('gray')
                    ->falseColor('danger')
                    ->tooltip(function (StaffUser $record): string {
                        $attempts = \App\Services\Auth\LoginThrottleService::attempts('staff', $record->email);
                        $isLocked = \App\Services\Auth\LoginThrottleService::isLocked('staff', $record->email);
                        $seconds = \App\Services\Auth\LoginThrottleService::availableIn('staff', $record->email);

                        if ($isLocked) {
                            return "Compte bloqué ({$attempts} tentatives) — Déblocage auto dans {$seconds}s";
                        }
                        if ($attempts > 0) {
                            return "{$attempts} tentative(s) échouée(s) enregistrée(s)";
                        }
                        return 'Accès normal (aucun verrou)';
                    })
                    ->toggleable(),

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

                Tables\Actions\Action::make('unlockLogin')
                    ->label('Débloquer Connexion')
                    ->icon('heroicon-o-lock-open')
                    ->color(fn (StaffUser $record): string => \App\Services\Auth\LoginThrottleService::isLocked('staff', $record->email) ? 'danger' : 'success')
                    ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin())
                    ->requiresConfirmation()
                    ->modalHeading(fn (StaffUser $record): string => "Débloquer la connexion : {$record->name}")
                    ->modalDescription(function (StaffUser $record): string {
                        $attempts = \App\Services\Auth\LoginThrottleService::attempts('staff', $record->email);
                        $isLocked = \App\Services\Auth\LoginThrottleService::isLocked('staff', $record->email);
                        $seconds = \App\Services\Auth\LoginThrottleService::availableIn('staff', $record->email);

                        if ($isLocked) {
                            return "Ce compte collaborateur a atteint {$attempts} tentatives de connexion infructueuses et est bloqué pour encore {$seconds} secondes. Voulez-vous réinitialiser le verrou et débloquer l'accès immédiatement ?";
                        }
                        if ($attempts > 0) {
                            return "Ce compte compte actuellement {$attempts} tentative(s) échouée(s). Voulez-vous réinitialiser le compteur à zéro ?";
                        }
                        return "Ce compte n'est pas bloqué (0 tentative échouée enregistrée). Souhaitez-vous forcer la réinitialisation des verrous de connexion ?";
                    })
                    ->modalSubmitActionLabel('Débloquer le compte')
                    ->action(function (StaffUser $record): void {
                        \App\Services\Auth\LoginThrottleService::unlock('staff', (string) $record->email);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Connexion débloquée')
                            ->body("Les tentatives de connexion pour « {$record->name} » ({$record->email}) ont été réinitialisées avec succès.")
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('unlockLoginBulk')
                        ->label('Débloquer la connexion')
                        ->icon('heroicon-o-lock-open')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Débloquer la connexion des collaborateurs sélectionnés')
                        ->modalDescription('Toutes les restrictions de tentatives de connexion (rate limiter) seront réinitialisées pour les comptes sélectionnés.')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->email) {
                                    \App\Services\Auth\LoginThrottleService::unlock('staff', $record->email);
                                    $count++;
                                }
                            }

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Comptes collaborateurs débloqués')
                                ->body("{$count} compte(s) collaborateur(s) ont été débloqués avec succès.")
                                ->send();
                        }),
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
