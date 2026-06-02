<?php

namespace App\Filament\Resources;

use App\Models\Invoice;
use App\Models\PaymentAccount;
use App\Models\Transaction;
use Filament\Tables;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use TomatoPHP\FilamentInvoices\Filament\Resources\InvoiceResource as BaseInvoiceResource;
use TomatoPHP\FilamentInvoices\Models\InvoicesItem;
use TomatoPHP\FilamentTypes\Models\Type;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Extended InvoiceResource with PaymentAccount integration
 *
 * Note: Uses local App\Models\Invoice which extends TomatoPHP's Invoice
 * This ensures payment_account_id is in the fillable array
 */
class InvoiceResource extends BaseInvoiceResource
{
    // Override to use local Invoice model with payment_account_id support
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 5;

    /**
     * Override getRelations
     */
    public static function getRelations(): array
    {
        return array_merge(parent::getRelations(), []);
    }

    /**
     * Override form to ensure payment_account_id field is available
     */
    public static function form(Form $form): Form
    {
        return parent::form($form);
    }

    /**
     * Override table to show PaymentAccount column
     */
    public static function table(Table $table): Table
    {
        return parent::table($table);
    }
}