# PRD - ERP Creative
## Product Requirements Document for Digital Agency Management System

**Version**: 4.0
**Last Updated**: 2026-07-14
**Company**: ERP Creative
**Type**: Business Management ERP for Digital Marketing Agency
**Repository**: https://github.com/holakrafty-byte/erp

---

## 1. Overview

**ERP Creative** adalah aplikasi ERP (Enterprise Resource Planning) berbasis Laravel yang dirancang khusus untuk **Digital Marketing Agency**. Aplikasi ini mengelola seluruh aspek operasional bisnis mulai dari CRM, Project Management, Finance, HR, hingga Social Media Analytics.

### 1.1 Tujuan Sistem
- Mengelola lifecycle lengkap client: Lead → Deal → Quotation → Invoice → Contract
- Mengelola project dan task dengan visualisasi Kanban
- Mengelola keuangan: Expense, Advance, Budget, Invoice dengan sistem Payment Account
- Mengelola HR: Employee, Absence, Event/Calendar
- Mengintegrasikan social media: Instagram, Facebook, Meta Ads
- Menyediakan dashboard analytics untuk pengambilan keputusan

### 1.2 Tech Stack

| Komponen | Teknologi |
|----------|-----------|
| **Backend** | Laravel 11 (PHP 8.3+) |
| **Frontend** | Filament v3 + Livewire + Alpine.js + TailwindCSS |
| **Auth** | Laravel Sanctum + Spatie Permission |
| **Database** | MySQL |
| **File Storage** | Spatie Media Library |
| **PDF** | Barryvdh DomPDF |
| **Admin Panel** | Filament Shield |
| **Invoice** | TomatoPHP Filament Invoices |
| **Kanban** | Mokhosh Filament Kanban |
| **Calendar** | Saade Filament FullCalendar |
| **Social API** | Meta Graph API v19 |
| **AI** | Google Gemini API |
| **WhatsApp** | Wablas API |

---

## 2. Business Flow

### 2.1 CRM Pipeline

```
┌─────────────┐     ┌─────────────┐     ┌──────────────┐     ┌─────────────┐     ┌─────────────┐
│   LEADS     │────▶│    DEAL     │────▶│  QUOTATION   │────▶│   INVOICE   │────▶│  CONTRACT   │
│   (Prospek) │     │  (Pipeline) │     │  (Penawaran) │     │ (Penagihan) │     │  (Kontrak)  │
└─────────────┘     └─────────────┘     └──────────────┘     └─────────────┘     └─────────────┘
     │                   │                   │                    │                    │
     ▼                   ▼                   ▼                    ▼                    ▼
  - Nama               - Nilai             - Items              - Items              - SOW
  - Contact            - Stage             - Tax (PPN)          - Tax (PPN)          - Fee (Tabel A)
  - Source             - Produk            - Total               - Total             - Third Party (Tabel a)
  - Status            - UUID              - Clone to Invoice    - Payment Account   - UUID
```

---

## 3. CRM Module

### 3.1 Lead Management

**Status Flow:**
```
New → Contacted → Qualified → Proposal → Won
                           ↘ Lost
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| nama_perusahaan | string | Nama perusahaan lead |
| contact_person | string | Nama kontak |
| email | string | Email utama |
| phone | string | Nomor telepon |
| source | enum | Asal lead (Instagram, Website, Referral, dll) |
| status | enum | Status pipeline |
| user_id | FK | Assigned sales person |
| notes | text | Catatan tambahan |

**Color Helper:** `ColorHelper::status($status)`

### 3.2 Deal Management

**Status Flow:**
```
New → Discovery → Proposal → Negotiation → Won
                        ↘ Lost
```

**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| nama_perusahaan | string | Nama deal/perusahaan |
| lead_id | FK | Relasi ke Lead |
| estimasi_nilai | decimal | Estimasi nilai deal |
| stage | enum | Stage pipeline |
| product_id | FK | Produk yang ditawarkan |
| user_id | FK | Assigned person |
| uuid | string | Auto-generated: `#DG/013YYMM/XXXXX` |

**UUID Generation:**
```php
// Format: #PREFIX/013YYMM/XXXXX
public static function generateUuid(string $type = 'sale'): string
{
    $prefix = match ($type) {
        'estimate' => 'PI',
        'sale' => 'DG',
        default => 'DG',
    };
    $datePart = now()->format('ym');
    $pattern = '#' . $prefix . '/013' . $datePart . '/%';

    $highestSeq = self::withTrashed()
        ->where('uuid', 'like', $pattern)
        ->get()
        ->map(fn($inv) => intval(substr($inv->uuid, -5)))
        ->max() ?? 0;

    $newNumber = str_pad($highestSeq + 1, 5, '0', STR_PAD_LEFT);
    return '#' . $prefix . '/013' . $datePart . '/' . $newNumber;
}
```

### 3.3 Quotation Management

**Status Flow:**
```
Draft → Sent → Viewed → Approved
                   ↘ Revised → Rejected
```

**Form Structure:**
```
┌─────────────────────────────────────────────────────────┐
│ HEADER                                                  │
│ ├─ Quotation Number (UUID)                             │
│ ├─ Date, Valid Until                                    │
│ └─ Deal Selection (auto-fill client)                   │
├─────────────────────────────────────────────────────────┤
│ CLIENT INFO (auto from Deal)                           │
│ ├─ Company Name                                        │
│ ├─ Address                                            │
│ └─ Contact Person                                     │
├─────────────────────────────────────────────────────────┤
│ ITEMS                                                   │
│ ├─ Toggle: [Manual Entry] [From Product]               │
│ └─ Repeater:                                          │
│     ├─ Description                                    │
│     ├─ Quantity                                       │
│     ├─ Unit Price                                     │
│     └─ Total (auto-calculate)                         │
├─────────────────────────────────────────────────────────┤
│ TOTALS                                                  │
│ ├─ Subtotal                                            │
│ ├─ Tax (PPN 11%)                                       │
│ └─ Grand Total                                         │
└─────────────────────────────────────────────────────────┘
```

**Key Features:**
- [x] Clone to Invoice
- [x] PDF generation
- [x] Repeater with `mutateRelationshipDataBeforeFillUsing`
- [x] Eager loading on Edit/View pages
- [x] SoftDeletes

### 3.4 Invoice Management

**Status Flow:**
```
Draft → Sent → Partial → Paid
                  ↘ Overdue → Cancelled
```

**Form Structure:**
```
┌─────────────────────────────────────────────────────────┐
│ DATA INVOICE                                            │
│ ├─ Invoice Number (UUID: #DG/013YYMM/XXXXX)           │
│ ├─ Date, Due Date                                      │
│ └─ Company (auto from Deal)                            │
├─────────────────────────────────────────────────────────┤
│ ITEMS                                                   │
│ ├─ Toggle: [Manual Entry] [From Product]              │
│ └─ Repeater:                                          │
│     ├─ Description                                    │
│     ├─ Quantity                                       │
│     ├─ Unit Price                                     │
│     └─ Total                                          │
├─────────────────────────────────────────────────────────┤
│ TOTAL                                                   │
│ ├─ Subtotal                                            │
│ ├─ Tax (PPN 11%)                                       │
│ └─ Grand Total                                         │
├─────────────────────────────────────────────────────────┤
│ PAYMENT                                                 │
│ └─ Payment Account (dropdown from payment_accounts)    │
└─────────────────────────────────────────────────────────┘
```

**Key Features:**
- [x] UUID format: `#DG/013YYMM/XXXXX` (sale) / `#PI/013YYMM/XXXXX` (estimate)
- [x] Company auto-fill from Deal
- [x] Items with Manual/Product toggle
- [x] Payment Account integration
- [x] Eager loading on Edit/View pages
- [x] SoftDeletes

### 3.5 Contract Management

**Status Flow:**
```
Draft → Active → Expired
           ↘ Terminated
```

**Form Structure:**
```
┌─────────────────────────────────────────────────────────┐
│ CONTRACT INFO                                           │
│ ├─ Contract Number                                     │
│ ├─ Effective Date, Expiration Date                     │
│ └─ Deal Selection                                      │
├─────────────────────────────────────────────────────────┤
│ CLIENT INFO                                             │
│ ├─ Company Name (full)                                 │
│ ├─ Address, Phone, Email                               │
│ └─ Signatory Name & Title                              │
├─────────────────────────────────────────────────────────┤
│ PROJECT DESCRIPTION                                     │
│ └─ Deskripsi singkat project                           │
├─────────────────────────────────────────────────────────┤
│ PASAL 2 - SCOPE OF WORK (HIERARCHICAL) ⚡              │
│                                                         │
│ Kategori Ruang Lingkup:                                │
│ ┌─────────────────────────────────────────────────────┐ │
│ │ 1. Social Media Management                          │ │
│ │    ├─ Produksi 20 materi/bulan                     │ │
│ │    ├─ 4 Instagram Story/bulan                      │ │
│ │    └─ Riset & Analisis                              │ │
│ ├─────────────────────────────────────────────────────┤ │
│ │ 2. Digital Ads                                     │ │
│ │    ├─ Facebook Ads Setup                           │ │
│ │    └─ Google Ads Management                         │ │
│ └─────────────────────────────────────────────────────┘ │
├─────────────────────────────────────────────────────────┤
│ DETAIL KONTRAK                                         │
│ ├─ Platform (Instagram, Facebook, TikTok)              │
│ ├─ Jumlah Konten/Bulan                                 │
│ ├─ Maksimal Revisi                                    │
│ └─ Durasi (Bulan)                                     │
├─────────────────────────────────────────────────────────┤
│ PEMBAYARAN                                             │
│ ├─ Payment Type (Full, DP+Monthly, Monthly)           │
│ ├─ Total Value                                        │
│ ├─ Down Payment %                                      │
│ └─ Installment Months                                 │
├─────────────────────────────────────────────────────────┤
│ LAMPIRAN 1 - RINCIAN BIAYA                            │
│                                                         │
│ Tabel A - Biaya Jasa:                                 │
│ ┌────────────────────────────────────────────────────┐ │
│ │ No │ Item      │ Estimasi │ Tanggal  │ Jumlah    │ │
│ │ 1  │ DP 50%    │ Rp 5M    │ 01/08/26 │ Rp 5M     │ │
│ │ 2  │ Sisa Bayar│ Rp 5M    │ 01/09/26 │ Rp 5M     │ │
│ │    │           │          │ TOTAL    │ Rp 10M    │ │
│ └────────────────────────────────────────────────────┘ │
│                                                         │
│ Tabel a - Biaya Pihak Ketiga: [TOGGLE ON/OFF]         │
│ ┌────────────────────────────────────────────────────┐ │
│ │ No │ Item           │ Amount                      │ │
│ │ 1  │ Google Ads     │ Rp 500.000                 │ │
│ └────────────────────────────────────────────────────┘ │
├─────────────────────────────────────────────────────────┤
│ KETENTUAN TAMBAHAN                                     │
│ ├─ Klausul Tambahan (Pasal 11)                        │
│ ├─ Klausul Denda (Pasal 12)                          │
│ └─ Force Majeure Extended                             │
└─────────────────────────────────────────────────────────┘
```

**Key Features:**
- [x] **Hierarchical SOW** - Categories with sub-items
- [x] **Tabel A** - Fee items (biaya jasa)
- [x] **Tabel a** - Third party (toggle ON/OFF)
- [x] SOW separated from Lampiran (PASAL 2)
- [x] PDF template with proper rendering
- [x] SoftDeletes

---

## 4. Contract SOW Implementation Details

### 4.1 Database Structure

```sql
-- contract_items table
id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
contract_id          BIGINT UNSIGNED
parent_id            BIGINT UNSIGNED NULL        -- NULL for category, ID for sub-items
item                 VARCHAR(255)                -- Category name or item name
description          TEXT NULL                   -- For sub-items
type                 ENUM('sow', 'fee', 'third_party')
sort_order           INT DEFAULT 0
is_category_header   BOOLEAN DEFAULT FALSE       -- TRUE for SOW categories
quantity             DECIMAL(10,2) NULL
unit                 VARCHAR(50) NULL
amount               DECIMAL(15,2) NULL
notes                TEXT NULL
deleted_at           TIMESTAMP NULL
created_at           TIMESTAMP
updated_at           TIMESTAMP

-- Indexes
INDEX idx_contract_type (contract_id, type)
INDEX idx_parent (parent_id)
```

### 4.2 Contract Model Relationships

```php
class Contract extends Model
{
    use SoftDeletes;

    // SOW Categories with children
    public function sowCategories(): HasMany
    {
        return $this->hasMany(ContractItem::class)
            ->where('type', 'sow')
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order');
    }

    // Fee Items (Tabel A)
    public function feeItems(): HasMany
    {
        return $this->hasMany(ContractItem::class)
            ->where('type', 'fee')
            ->orderBy('sort_order');
    }

    // Third Party Items (Tabel a)
    public function thirdPartyItems(): HasMany
    {
        return $this->hasMany(ContractItem::class)
            ->where('type', 'third_party')
            ->orderBy('sort_order');
    }
}
```

### 4.3 ContractItem Model

```php
class ContractItem extends Model
{
    protected $fillable = [
        'contract_id', 'parent_id', 'item', 'description',
        'type', 'sort_order', 'is_category_header',
        'quantity', 'unit', 'amount', 'notes'
    ];

    protected $casts = [
        'is_category_header' => 'boolean',
        'amount' => 'decimal:2',
        'quantity' => 'decimal:2',
    ];

    public function children(): HasMany
    {
        return $this->hasMany(ContractItem::class, 'parent_id')
            ->orderBy('sort_order');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class, 'parent_id');
    }

    public function scopeRootSow(Builder $query): Builder
    {
        return $query->where('type', 'sow')->whereNull('parent_id');
    }

    public function scopeCategoryHeaders(Builder $query): Builder
    {
        return $query->where('is_category_header', true);
    }
}
```

### 4.4 Form Implementation

```php
// ContractResource.php - SOW Section
Forms\Components\Section::make('Ruang Lingkup Pekerjaan')
    ->schema([
        // Hierarchical Repeater
        Forms\Components\Repeater::make('sow_categories')
            ->mutateRelationshipDataBeforeFillUsing(function ($record): array {
                $record->load(['sowCategories' => fn($q) => $q->with('children')]);
                return $record->sowCategories->map(fn($cat) => [
                    'item' => $cat->item,
                    'children' => $cat->children->map(fn($c) => [
                        'item' => $c->item,
                        'description' => $c->description,
                    ])->toArray(),
                ])->toArray();
            })
            ->schema([
                TextInput::make('item')
                    ->label('Nama Kategori'),
                Forms\Components\Repeater::make('children')
                    ->schema([
                        TextInput::make('item')->label('Nama Item'),
                        Textarea::make('description')->label('Deskripsi'),
                    ]),
            ]),
    ])

// Third Party Toggle
Toggle::make('has_third_party')
    ->label('Aktifkan Tabel a (Biaya Pihak Ketiga)')
    ->default(false)
    ->hidden(fn(callable $get) => !$get('has_third_party'))
```

### 4.5 Save Hooks

```php
// CreateContract.php / EditContract.php
protected function afterCreate(): void
{
    if (!empty($this->sowCategoriesData)) {
        $this->saveSowCategories($this->record, $this->sowCategoriesData);
    }
}

private function saveSowCategories(Model $contract, array $categories): void
{
    foreach ($categories as $sortOrder => $categoryData) {
        $category = ContractItem::create([
            'contract_id' => $contract->id,
            'item' => $categoryData['item'],
            'type' => 'sow',
            'sort_order' => $sortOrder + 1,
            'is_category_header' => true,
        ]);

        foreach ($categoryData['children'] ?? [] as $childSortOrder => $child) {
            ContractItem::create([
                'contract_id' => $contract->id,
                'parent_id' => $category->id,
                'item' => $child['item'],
                'description' => $child['description'] ?? '',
                'type' => 'sow',
                'sort_order' => $childSortOrder + 1,
                'is_category_header' => false,
            ]);
        }
    }
}
```

### 4.6 PDF Template Rendering

```php
// ContractTemplateService.php
private function buildSowTable(Contract $contract): string
{
    $categories = $contract->sowCategories ?? collect();

    // Render hierarchical structure
    if ($categories->isNotEmpty()) {
        $rowNum = 1;
        foreach ($categories as $category) {
            // Category header (gray background, bold)
            $html .= '<tr style="background-color: #f0f0f0;">
                <td style="font-weight: bold;">' . $rowNum . '</td>
                <td colspan="4" style="font-weight: bold;">' . $category->item . '</td>
            </tr>';

            // Children (indented)
            foreach ($category->children as $child) {
                $html .= '<tr>
                    <td>' . $rowNum . '.' . $childNum . '</td>
                    <td style="padding-left: 20px;">' . $child->item . '</td>
                    <td>' . $child->description . '</td>
                    <td>' . $child->quantity . '</td>
                    <td>' . $child->unit . '</td>
                </tr>';
            }
            $rowNum++;
        }
    }
}

// Table a (conditional)
if ($contract->has_third_party) {
    // Render third party items
}
```

---

## 5. Finance Module

### 5.1 Expense
| Field | Type |
|-------|------|
| description | string |
| amount | decimal |
| category | enum |
| date | date |
| user_id | FK |
| project_id | FK |

**Categories:** Operational, Marketing, Office Supplies, Travel, Other

### 5.2 Advance
| Field | Type |
|-------|------|
| employee_id | FK |
| amount | decimal |
| purpose | string |
| status | enum |
| date | date |

**Status:** Pending → Approved → Paid / Rejected

### 5.3 Budget
- Budget per project/department
- Track actual vs budget
- Alert when over budget

### 5.4 Payment Account
| Field | Type |
|-------|------|
| bank_name | string |
| account_number | string |
| account_name | string |
| is_active | boolean |

---

## 6. HR Module

### 6.1 Employee
| Field | Type |
|-------|------|
| name | string |
| email | string |
| phone | string |
| position | string |
| department | string |
| join_date | date |
| salary | decimal |

### 6.2 Absence
| Field | Type |
|-------|------|
| employee_id | FK |
| type | enum |
| start_date | date |
| end_date | date |
| reason | string |
| status | enum |

**Categories:**
- Sick (Sakit)
- Permission (Izin)
- Annual Leave (Cuti)
- Unpaid Leave (Cuti Tidak Dibayar)

---

## 7. Social Media Module

### 7.1 Instagram Sync
- OAuth integration
- Post analytics
- Story & Reels tracking

### 7.2 Facebook Page Sync
- Page metrics
- Engagement tracking

### 7.3 Meta Ads Integration
- Campaign overview
- Ad spend tracking
- Performance metrics

---

## 8. Technical Patterns

### 8.1 UUID Generation
```php
// Format: #PREFIX/013YYMM/XXXXX
// Sale Invoice: #DG/0132607/00001
// Estimate/PI: #PI/0132607/00001
```

### 8.2 Role-Based Access
```php
$user->hasRole(['Director', 'Finance', 'super_admin'])
$user->hasRole(['Project Manager'])
$user->hasRole(['Marketing'])
```

### 8.3 Soft Deletes
All main models use SoftDeletes trait:
- Invoice
- Quotation
- Contract

### 8.4 Eager Loading
```php
// On Edit/View pages
protected function mutateFormDataBeforeFill(array $data): array
{
    $this->record->load([
        'feeItems',
        'thirdPartyItems',
        'sowCategories' => fn($q) => $q->with('children'),
        'deal',
    ]);
    return $data;
}
```

### 8.5 Color Helpers
```php
ColorHelper::status($status)         // Generic status
ColorHelper::dealStatus($status)    // Deal-specific
ColorHelper::absenceCategory($cat)   // Absence category
```

---

## 9. Database Schema

### 9.1 Core Tables

| Table | Description |
|-------|------------|
| `users` | User accounts with roles |
| `leads` | Lead/customer prospects |
| `deals` | Sales pipeline |
| `quotations` | Quote documents |
| `quotation_items` | Quote line items |
| `invoices` | Invoice documents |
| `invoices_items` | Invoice line items |
| `contracts` | Contract documents |
| `contract_items` | SOW, Fee, Third Party items |
| `projects` | Projects |
| `tasks` | Kanban tasks |
| `task_stages` | Kanban stages |
| `expenses` | Expense records |
| `advances` | Advance payments |
| `budgets` | Budget allocations |
| `payment_accounts` | Bank accounts for payments |
| `employees` | Employee records |
| `absences` | Absence records |
| `instagram_accounts` | Social media sync |
| `facebook_pages` | Facebook pages |

### 9.2 Recent Migrations

```sql
-- 2026_07_13_220034_add_has_third_party_to_contracts_table
ALTER TABLE contracts ADD COLUMN has_third_party BOOLEAN DEFAULT FALSE;

-- contract_items table additions
ALTER TABLE contract_items ADD COLUMN parent_id BIGINT UNSIGNED NULL;
ALTER TABLE contract_items ADD COLUMN is_category_header BOOLEAN DEFAULT FALSE;
```

---

## 10. Deployment Guide

### 10.1 Local Development
```bash
git clone https://github.com/holakrafty-byte/erp.git
cd erp
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### 10.2 Hosting Deployment (Niagahoster)
```bash
# SSH to server
ssh user@erp-digitalk.com

# Navigate to app directory
cd ~/erp-digitalk.com

# Clone/update
git clone https://github.com/holakrafty-byte/erp.git erp-app
cd erp-app

# Setup
cp .env.example .env
# Edit .env with hosting database credentials

composer install --no-dev --optimize-autoloader

php artisan key:generate --force

chmod -R 775 storage bootstrap/cache

php artisan migrate --force

php artisan optimize:clear
php artisan config:cache
php artisan route:cache

# Point public_html to erp-app/public
cd ~/erp-digitalk.com
rm public_html
ln -s erp-app/public public_html
```

### 10.3 Requirements
- PHP 8.3+
- MySQL 8.0+ / MariaDB 10.4+
- Composer 2.x
- Extensions: GD, Zip, XML, MBString, PDO

---

## 11. Feature Updates Log

### v4.0 (July 14, 2026)

#### Invoice Restructure
- [x] Company from Deal (auto-fill)
- [x] Items with Manual/Product toggle
- [x] Total at bottom (Subtotal, Tax, Grand Total)
- [x] UUID format: `#DG/013YYMM/XXXXX`
- [x] Payment Account integration
- [x] Eager loading on Edit/View pages

#### Contract SOW Hierarchical
- [x] Parent-child structure (category > sub-items)
- [x] Add/remove categories dynamically
- [x] Add/remove sub-items per category
- [x] Render in PDF template
- [x] PASAL 2 structure in contract

#### Contract Lampiran Restructure
- [x] **Tabel A:** Fee Items only (no SOW)
- [x] **Tabel a:** Third Party (toggle ON/OFF)
- [x] Conditional rendering in PDF
- [x] `has_third_party` boolean field

#### Database Changes
- [x] `has_third_party` column in contracts
- [x] `parent_id`, `is_category_header` in contract_items

#### Soft Deletes
- [x] Invoice
- [x] Quotation
- [x] Contract

---

## 12. Known Issues & Solutions

| Issue | Solution |
|-------|----------|
| PDF not showing | `php artisan view:clear && php artisan cache:clear` |
| Token errors | Check APP_KEY in .env |
| Widget not loading | Verify widget class extends correctly |
| Kanban not working | Verify TaskStages enum has all status values |
| Repeater not filling | Use `mutateRelationshipDataBeforeFillUsing` |
| Foreign key constraint | Add SoftDeletes trait to models |

---

## 13. Navigation Structure

```
CRM
├── Leads
├── Client
├── Product
├── Quotation
└── Contract

Project
├── Project
├── Task Kanban
└── Timeline

Finance
├── Expense
├── Advance
├── Budget
├── Invoice
└── Analytics

HR
├── Employee
└── Absence
```

---

## 14. Key Commands

```bash
# Run migrations
php artisan migrate

# Clear cache
php artisan optimize:clear

# Rebuild assets
npm run dev
php artisan view:clear

# Generate keys
php artisan key:generate

# Sync social accounts
php artisan sync:instagram
php artisan sync:meta-ads

# Check routes
php artisan route:list

# Check permissions
php artisan permission:cache-reset
```

---

**Document End**
