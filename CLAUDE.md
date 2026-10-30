# CLAUDE.md - ERP Creative

> This file provides AI context for working with the ERP Creative codebase.
> Always read this file before starting work.

## Quick Start

1. **Read the PRD** at `docs/PRD-ERP-CREATIVE.md` for complete business context
2. **Read the Summary** at `docs/ERP-CODEBASE-SUMMARY.md` for technical overview
3. **Key files** are documented in the Summary

## Project Type

- **Framework**: Laravel 11 + Filament v3 (Admin Panel)
- **Purpose**: ERP for Digital Marketing Agency (ERP Creative)
- **Language**: PHP 8.3+

## Key Context for AI

### Business Domain
- CRM: Leads → Deal → Quotation → Invoice → Contract
- Project Management: Deal → Project → Task (Kanban)
- Finance: Expense, Advance, Budget tracking
- HR: Employee, Absence management
- Social Media / Meta Ads: removed 2026-10-30 (tables stay in the DB, code is gone)

### Important Patterns

```php
// UUID Generation
$prefix = '#DG/';
$datePart = now()->format('my') . '/';
// Format: #DG/YYMM/XXXXX

// Role Check
$user->hasRole(['Director', 'Finance', 'super_admin'])

// Token Encryption
'access_token' => 'encrypted' // in $casts array

// Soft Deletes
use Illuminate\Database\Eloquent\SoftDeletes;

// Observer Pattern
protected static function boot() {
    parent::boot();
    static::observe(TaskObserver::class);
}
```

### Navigation Groups
- CRM → Leads, Client, Product, Quotation, Contract
- Project → Project, Task Kanban, Timeline
- Finance → Expense, Advance, Budget, Invoice, Analytics
- HR → Employee, Absence

### Color Helper Usage
```php
ColorHelper::status($status)      // generic status
ColorHelper::dealStatus($status)  // deal-specific
ColorHelper::absenceCategory($cat) // absence category
```

### Critical Services
- `ContractTemplateService` - Generates contract HTML
- `MetricsEngine` - Financial metrics calculation

## File Locations

| Type | Location |
|------|----------|
| Models | `app/Models/` |
| Resources | `app/Filament/Resources/` |
| Services | `app/Services/` |
| Pages | `app/Filament/Pages/` |
| Settings | `app/Settings/` |
| Helpers | `app/Helpers/` |
| Observers | `app/Observers/` |
| Routes | `routes/web.php` |

## Commands

```bash
# Run migrations
php artisan migrate

# Clear cache
php artisan cache:clear

# Rebuild assets
npm run dev
php artisan view:clear

# Generate keys
php artisan key:generate

# Scheduled reminders (app/Console/Kernel.php); the server needs a cron every minute
bash scripts/schedule-run.sh          # = php artisan schedule:run
php artisan ringkasan:pagi --dry-run  # preview the morning brief without sending
```

## Common Fixes

1. **PDF not showing**: Run `php artisan view:clear && php artisan cache:clear`
2. **Token errors**: Check APP_KEY in .env (re-encrypt tokens if changed)
3. **Widget not loading**: Check widget class extends correctly
4. **Kanban not working**: Verify TaskStages enum has all status values

## Environment Variables

Key `.env` variables:
- `APP_KEY` - For token encryption
- `MAIL_MAILER` - Mail configuration
- `FILESYSTEM_DISK` - Storage driver
- `DB_CONNECTION` - Database type

## Testing Commands

```bash
# Run tests
php artisan test

# Check routes
php artisan route:list

# Check permissions
php artisan permission:cache-reset
```