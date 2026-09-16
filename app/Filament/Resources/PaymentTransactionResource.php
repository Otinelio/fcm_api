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

    protected static ?string $navigationGroup = 'Finance & Abonnements';

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Transactions FedaPay';

    protected static ?string $pluralModelLabel = 'Transactions FedaPay';

    protected static ?string $modelLabel = 'Transaction FedaPay';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('restaurant_subscription_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('fedapay_transaction_id')
                    ->maxLength(255),
                Forms\Components\TextInput::make('fedapay_reference')
                    ->maxLength(255),
                Forms\Components\TextInput::make('amount_xof')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('mode')
                    ->maxLength(255),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255)
                    ->default('pending'),
                Forms\Components\TextInput::make('raw_payload'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('restaurant_subscription_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fedapay_transaction_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fedapay_reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount_xof')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('mode')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListPaymentTransactions::route('/'),
            'create' => Pages\CreatePaymentTransaction::route('/create'),
            'edit' => Pages\EditPaymentTransaction::route('/{record}/edit'),
        ];
    }
}
