<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoyaltyTransactionResource\Pages;
use App\Models\LoyaltyTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LoyaltyTransactionResource extends Resource
{
    protected static ?string $model = LoyaltyTransaction::class;

    protected static ?string $navigationGroup = 'Gestion Clients';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Transactions Fidélité';

    protected static ?string $pluralModelLabel = 'Transactions de Fidélité';

    protected static ?string $modelLabel = 'Transaction de Fidélité';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('loyalty_card_id')
                    ->relationship('loyaltyCard', 'id'),
                Forms\Components\Select::make('staff_user_id')
                    ->relationship('staffUser', 'name'),
                Forms\Components\TextInput::make('type')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('value')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('montant_commande_fcfa')
                    ->numeric(),
                Forms\Components\TextInput::make('validation_method')
                    ->maxLength(255),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255)
                    ->default('valid'),
                Forms\Components\Select::make('canceled_by_staff_user_id')
                    ->relationship('canceledByStaffUser', 'name'),
                Forms\Components\DateTimePicker::make('canceled_at'),
                Forms\Components\TextInput::make('meta'),
                Forms\Components\Toggle::make('is_suspicious')
                    ->required(),
                Forms\Components\TextInput::make('suspicious_reason')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('loyaltyCard.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('staffUser.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('value')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('montant_commande_fcfa')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('validation_method')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('canceledByStaffUser.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('canceled_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_suspicious')
                    ->boolean(),
                Tables\Columns\TextColumn::make('suspicious_reason')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListLoyaltyTransactions::route('/'),
            'create' => Pages\CreateLoyaltyTransaction::route('/create'),
            'edit' => Pages\EditLoyaltyTransaction::route('/{record}/edit'),
        ];
    }
}
