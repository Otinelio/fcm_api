<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentTransactionResource\Pages;
use App\Models\PaymentTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentTransactionResource extends Resource
{
    protected static ?string $model = PaymentTransaction::class;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Paiements';

    protected static ?string $pluralModelLabel = 'Transactions FedaPay';

    protected static ?string $modelLabel = 'Transaction FedaPay';

    protected static ?int $navigationSort = 3;

    /**
     * Temporairement indisponible : FedaPay désactivé.
     * Conserve le code pour réactivation ultérieure sans suppression.
     */
    public static function canAccess(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails du Paiement Marchand')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('restaurant_subscription_id')
                                    ->label('Abonnement Concerné')
                                    ->relationship('subscription', 'id')
                                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->restaurant?->name} - {$record->plan?->name}")
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\TextInput::make('fedapay_reference')
                                    ->label('Référence FedaPay')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('fedapay_transaction_id')
                                    ->label('ID Transaction FedaPay')
                                    ->maxLength(255),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('amount_xof')
                                    ->label('Montant (FCFA)')
                                    ->numeric()
                                    ->suffix('FCFA')
                                    ->required(),

                                Forms\Components\TextInput::make('mode')
                                    ->label('Moyen de Paiement')
                                    ->placeholder('Ex: mtn, moov, carte')
                                    ->maxLength(255),

                                Forms\Components\Select::make('status')
                                    ->label('Statut')
                                    ->options([
                                        'approved' => 'Validée / Payée',
                                        'pending' => 'En attente',
                                        'declined' => 'Refusée',
                                        'canceled' => 'Annulée',
                                    ])
                                    ->default('pending')
                                    ->required(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date & Heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('fedapay_reference')
                    ->label('Référence FedaPay')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Référence copiée')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('subscription.restaurant.name')
                    ->label('Établissement')
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('amount_xof')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->color('success')
                    ->weight('bold')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('mode')
                    ->label('Mode')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn ($state) => strtoupper((string) $state))
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'declined', 'canceled' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'approved' => 'Validée',
                        'pending' => 'En attente',
                        'declined' => 'Refusée',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucune transaction financière')
            ->emptyStateDescription('Les paiements d\'abonnement via FedaPay s\'afficheront ici.')
            ->emptyStateIcon('heroicon-o-currency-dollar');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentTransactions::route('/'),
            'create' => Pages\CreatePaymentTransaction::route('/create'),
            'edit' => Pages\EditPaymentTransaction::route('/{record}/edit'),
        ];
    }
}
