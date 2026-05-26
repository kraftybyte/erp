# PRD - ERP Creative (Digital Agency Management System)

> **Purpose**: This document provides comprehensive context about the ERP Creative application to enable AI-assisted code analysis, debugging, feature development, and refactoring.
>
> **Last Updated**: 2026-05-26
>
> **Company**: ERP Creative
>
> **Type**: Business Management ERP for Digital Marketing Agency
>
> **Version**: 2.0

---

## CHANGELOG (v2.0)

### Recent Updates (2026-05-26)
- ✅ Invoice Template Settings - UI untuk customize invoice
- ✅ Invoice PDF Template - Dynamic PDF dengan customizable settings
- ✅ Font Sora - Global typography untuk semua UI
- ✅ Header Layout Fix - Balance text dengan logo di invoice PDF
- ✅ Settings Database Migration - Semua invoice settings di database

### Invoice Template Settings
- **inv_theme_color** - Warna tema invoice
- **inv_logo** - Logo untuk header invoice
- **inv_company_name** - Nama perusahaan
- **inv_company_address** - Alamat lengkap
- **inv_company_phone** - Nomor telepon
- **inv_bank_name** - Nama bank
- **inv_bank_account** - Nomor rekening
- **inv_bank_account_name** - Nama penerima
- **inv_signature_name** - Nama penanda tangan
- **inv_signature_image** - Gambar tanda tangan

---

## 1. BUSINESS CONTEXT

### 1.1 Company Profile
- **Business Type**: Digital Marketing Agency
- **Services Offered**:
  - Social Media Management
  - Video Production
  - SEO Services
  - TikTok Handling
  - Website Development
  - Live TikTok Sessions
  - Photo Product

### 1.2 Core Business Processes

```
┌─────────────┐     ┌─────────────┐     ┌──────────────┐     ┌─────────────┐     ┌─────────────┐
│   LEADS     │────▶│    DEAL     │────▶│  QUOTATION   │────▶│   INVOICE   │────▶│  CONTRACT   │
│   (Prospek) │     │  (Klien)    │     │  (Penawaran) │     │ (Proforma/  │     │  (Kontrak)  │
│             │     │             │     │              │     │  Sale)      │     │             │
└─────────────┘     └──────┬──────┘     └──────────────┘     └─────────────┘     └─────────────┘
                           │
                           ▼
                    ┌──────────────┐
                    │   PROJECT    │
                    │   (Proyek)   │
                    └──────┬───────┘
                           │
                           ▼
                    ┌──────────────┐     ┌──────────────┐
                    │    TASK      │────▶│   KANBAN     │
                    │  (Pekerjaan) │     │   BOARD      │
                    └──────────────┘     └──────────────┘
```

### 1.3 Finance Flow

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   ADVANCE   │────▶│  DISETUJUI  │────▶│ DIBAYARKAN  │────▶│  SELESAI    │     │   BUDGET    │
│  (Uang Muka)│     │  (Approved) │     │ (Paid)      │     │ (Completed) │     │  (Ter跟踪)  │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘

┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   EXPENSE   │────▶│  DISETUJUI  │────▶│ DIBAYARKAN  │────▶│  SELESAI    │
│ (Pengeluaran│     │  (Approved) │     │ (Paid)      │     │ (Completed) │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
```

---

## 2. TECHNICAL ARCHITECTURE

### 2.1 Stack Overview

```
┌─────────────────────────────────────────────────────────────┐
│                        FRONTEND                              │
│                    Filament v3 (Laravel)                     │
│                    + Livewire + Alpine.js                    │
│                    + TailwindCSS                              │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                        BACKEND                               │
│                    Laravel 11 (PHP 8.3+)                      │
└─────────────────────────────────────────────────────────────┘
                              │
          ┌───────────────────┼───────────────────┐
          ▼                   ▼                   ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│     MySQL        │  │   File Storage  │  │   External APIs │
│   (Database)     │  │  (Spatie Media)  │  │   (Meta, WA)    │
└─────────────────┘  └─────────────────┘  └─────────────────┘
```

### 2.2 Key Packages (composer.json)

| Package | Version | Purpose |
|---------|---------|---------|
| `filament/filament` | ^3.3 | Admin panel framework |
| `bezhansalleh/filament-shield` | ^3.3 | Role-based access control |
| `tomatophp/filament-invoices` | ^1.0 | Invoice management |
| `mokhosh/filament-kanban` | ^2.11 | Kanban board for tasks |
| `spatie/laravel-permission` | ^6.10 | Permission system |
| `laravel/sanctum` | ^4.0 | API authentication |
| `barryvdh/laravel-dompdf` | ^3.1 | PDF generation |
| `facebook/php-business-sdk` | ^24.0 | Meta Marketing API |
| `openai-php/laravel` | ^0.18.0 | AI integration |
| `jeffgreco13/filament-breezy` | ^2.5 | User profile management |
| `saade/filament-fullcalendar` | ^3.2 | Calendar view |
| `spatie/laravel-model-caching` | ^11.0 | Query caching |

### 2.3 Directory Structure

```
app/
├── Console/Commands/           # Artisan commands (Meta sync, token refresh, etc.)
├── Enums/                      # TaskStages, EventCategory
├── Filament/
│   ├── Pages/                  # Custom pages
│   │   ├── Dashboard.php       # Main dashboard
│   │   ├── AnalyticsDashboard.php  # Financial analytics
│   │   ├── TaskKanbanBoard.php # Kanban board (Mokhosh)
│   │   ├── ProjectTimelineBoard.php
│   │   └── PwaSettings.php
│   ├── Resources/              # Filament resources (CRUD)
│   │   ├── LeadsResource.php
│   │   ├── DealResource.php
│   │   ├── ProjectResource.php
│   │   ├── TaskResource.php
│   │   ├── ExpenseResource.php
│   │   ├── AdvanceResource.php
│   │   ├── QuotationResource.php
│   │   ├── ContractResource.php
│   │   ├── EmployeeResource.php
│   │   ├── AbsenceResource.php
│   │   ├── SocialAccountResource.php
│   │   └── MetaAdAccountResource.php
│   └── Widgets/                # Dashboard widgets
│       ├── InvoiceStatsOverview.php
│       ├── InvoiceRevenueChart.php
│       ├── RecentInvoicesTable.php
│       └── TopExpensesChart.php
├── Helpers/                   # Utility classes
│   ├── ColorHelper.php        # Status color mapping
│   └── NotifHelper.php        # Notification helper
├── Http/Controllers/
│   ├── QuotationPdfController.php
│   ├── InvoicePdfController.php
│   ├── ContractPdfController.php
│   ├── WablasController.php
│   └── Auth/InstagramConnectController.php
├── Jobs/                      # Background jobs
│   ├── SyncSocialAccountJob.php
│   ├── SyncMetaAdsJob.php
│   └── ImportAndSyncMetaJob.php
├── Models/                   # Eloquent models (36 total)
├── Notifications/            # Notification classes
│   ├── AbsenceApprovalNotification.php
│   └── PurchaseRequestApprovalNotification.php
├── Observers/                # Model observers
│   ├── ExpenseObserver.php
│   ├── AdvanceObserver.php
│   ├── AbsenceObserver.php
│   ├── BudgetObserver.php
│   └── TaskObserver.php
├── Policies/                 # Authorization policies (27)
├── Providers/
│   ├── FilamentServiceProvider.php
│   ├── Filament/AdminPanelProvider.php  # Filament panel config
│   └── ClickupThemeServiceProvider.php
├── Services/                 # Business logic
│   ├── ContractTemplateService.php     # Generate contract HTML
│   ├── MetricsEngine.php               # Financial metrics
│   ├── InstagramService.php            # Instagram API sync
│   ├── FacebookPageService.php         # Facebook sync
│   ├── MetaAdsService.php              # Meta Ads sync
│   ├── PushNotificationService.php    # Web push
│   ├── GeminiService.php               # AI analysis
│   ├── WablasService.php               # WhatsApp API
│   ├── TrendEngine.php                # Historical trends
│   ├── FunnelIntelligenceEngine.php   # CRM metrics
│   └── FileService.php                 # File operations
└── Settings/
    └── GeneralSettings.php    # App configuration (Spatie Settings)
```

---

## 3. DATA MODELS

### 3.1 Entity Relationship Diagram

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│    LEADS     │     │    DEAL      │     │   PRODUCT    │
├──────────────┤     ├──────────────┤     ├──────────────┤
│ id           │◀─── │ id           │     │ id           │
│ nama_pic     │     │ nama_perush  │◀───┐│ nama_layanan │
│ perusahaan   │     │ lead_id (FK) │     │ harga        │
│ email        │     │ status       │     └──────────────┘
│ telephone    │     └──────┬───────┘            │
│ source       │            │            ┌─────────┴────────┐
│ status       │            │            │    lead_product │ (pivot)
│ is_converted │            │            │    deal_product │ (pivot)
└──────────────┘            │            └─────────────────┘
                           │
       ┌───────────────────┼───────────────────┐
       ▼                   ▼                   ▼
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│   PROJECT    │     │  QUOTATION   │     │   CONTRACT   │
├──────────────┤     ├──────────────┤     ├──────────────┤
│ id           │     │ id           │     │ id           │
│ deal_id (FK) │     │ uuid (#DG/)  │     │ deal_id (FK) │
│ nama_project │     │ perusahaan   │     │ type         │
│ timeline     │     │ subtotal    │     │ total_value  │
│ status       │     │ grand_total  │     │ effective_dt │
└──────┬───────┘     └──────┬───────┘     └──────────────┘
       │                   │
       │                   ▼
       │            ┌──────────────┐     ┌──────────────┐
       │            │QuotationItem │     │   INVOICE    │
       │            ├──────────────┤     │(TomatoPHP)   │
       │            │ quotation_id │     ├──────────────┤
       │            │ item         │     │ id           │
       │            │ price, qty   │     │ uuid (#PI/)  │
       └───────────▶│ discount     │     │ type         │
                   │ vat          │     │ status       │
                   └──────────────┘     │ paid         │
                                        └──────────────┘

┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│     TASK     │     │   EXPENSE   │     │   ADVANCE    │
├──────────────┤     ├──────────────┤     ├──────────────┤
│ id           │     │ id           │     │ id           │
│ project_id   │     │ user_id (FK) │     │ user_id (FK) │
│ nama_task    │     │ amount      │     │ amount       │
│ status       │     │ status      │     │ status       │
│ proritas     │     │ paid_by     │     │ paid_by      │
│ mulai_task   │     │ reference   │     └──────────────┘
│ selesai_task │     └──────────────┘
└──────────────┘            │              ┌──────────────┐
                            │              │    BUDGET    │
                            ▼              ├──────────────┤
                      ┌──────────────┐     │ id           │
                      │  ITEM_EXP    │     │ amount       │
                      ├──────────────┤     │ name         │
                      │ expense_id   │     └──────┬───────┘
                      │ name         │            │
                      │ price        │            ▼
                      │ quantity     │     ┌──────────────┐
                      └──────────────┘     │  BUDGET_LOG  │
                                         ├──────────────┤
┌──────────────┐                          │ budget_id    │
│    USER     │     ┌──────────────┐     │ expense_id   │
├──────────────┤     │   ABSENCE   │     │ advance_id   │
│ id           │     ├──────────────┤     │ invoice_id   │
│ firstname    │     │ user_id (FK)│     └──────────────┘
│ lastname     │
│ email        │     │ category    │
│ position     │     │ approval_st │
│ division     │     │ sick_letter │
│ nik          │     │ work_report │
└──────────────┘     └──────────────┘
       │
       ▼
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│    EVENT     │     │   ASSET     │     │  SOCIAL_ACC  │
├──────────────┤     ├──────────────┤     ├──────────────┤
│ id           │     │ id           │     │ id           │
│ title        │     │ nama_barang  │     │ client_id    │
│ start_time   │     │ harga        │     │ platform     │
│ end_time     │     │ kondisi      │     │ access_token │
│ color        │     │ penanggung   │     │ followers    │
│ create_by    │     │ (user FK)   │     │ is_active    │
└──────────────┘     └──────────────┘     └──────────────┘
```

### 3.2 Model Details

#### User (users table)
```php
// Key fields
- id, username, email, password
- firstname, lastname (computed: name attribute)
- nik, phone, address, position, npwp, ktp, file_npwp
- division (Social Media Specialist, Finance, HR, Graphic Design, Video Editor)
- push_subscription, push_enabled (for PWA)

// Relationships
- tasks() → belongsToMany via task_user pivot
- expenses() → hasMany (as submitter)
- advances() → hasMany
- absences() → hasMany
- assets() → hasMany (penanggung_jawab)
- events() → hasMany (create_by)
- purchaseRequests() → hasMany

// Implements
- FilamentUser (canAccessPanel)
- HasAvatar, HasName, HasMedia (Spatie)
- HasRoles (Spatie Permission)
```

#### Leads (leads table)
```php
// Key fields
- id, nama_pic, nama_perusahaan, email, telephone
- source (Website, Social Media, Email Marketing, Event, Referral, Partner)
- status (Dalam Komunikasi, Proposal Sudah Dikirim, Menang, Kalah)
- is_converted (boolean)
- product_id (many-to-many)

// Key relationships
- product() → belongsToMany via lead_product
- deal() → hasOne (converted to Deal)
```

#### Deal (deals table)
```php
// Key fields
- id, lead_id (nullable, original lead)
- nama_pic, nama_perusahaan, email, telephone
- NPWP, alamat, notes
- status (Negosiasi, Menunggu Pembayaran, Dalam Tahap Project, Selesai, Hold)
- product_id (many-to-many)

// Key relationships
- lead() → belongsTo (Leads)
- product() → belongsToMany via deal_product
- project() → hasMany
- tasks() → hasManyThrough via Project
- invoices() → hasMany (by nama_perusahaan)
- quotations() → hasMany (by nama_perusahaan)
- contracts() → hasMany
- socialAccounts() → hasMany (client_id)
- metaAdAccounts() → hasMany (client_id)
```

#### Project (projects table)
```php
// Key fields
- id, deal_id (FK)
- nama_project (category: Social Media Management, Video Production, SEO, etc.)
- detail_project (markdown)
- timeline_mulai, timeline_selesai
- nama_perusahaan, alamat, nama_pic, notes

// Key relationships
- deal() → belongsTo
- product() → belongsTo
- tasks() → hasMany
- timelines() → hasMany (ProjectTimeline)
```

#### Task (tasks table)
```php
// Key fields
- id, project_id, deal_id
- nama_task, detail_task (rich text)
- mulai_task, selesai_task (dates)
- status (Log, Process, Review, Done, Hold) - via TaskStages enum
- proritas (Urgent, Normal, Low)
- nama_layanan, creator_id

// Relationships
- users() → belongsToMany via task_user pivot
- project() → belongsTo
- deal() → belongsTo
- performanceIndicator() → hasOne

// Observers
- TaskObserver: triggers notification on assignment
```

#### Quotation (quotations table)
```php
// Key fields
- id, uuid (#DG/YYMM/XXXXX format)
- nama_perusahaan, telephone, nama_pic, email
- date, due_date
- status (Pending, Approved, Rejected, Converted)
- subtotal, total_discount, total_vat, grand_total

// Key relationships
- items() → hasMany (QuotationItem)

// Business rules
- UUID auto-generated: #DG/{MMYY}/{00001}
- Auto-fill from Leads on nama_perusahaan selection
- Convert to Proforma Invoice creates Invoice with same items
```

#### Contract (contracts table)
```php
// Key fields
- id, deal_id (FK)
- type (editor = generated HTML, file = uploaded PDF)
- scope_of_work, platform, content_count, max_revisions
- duration_months, effective_date, expiration_date
- total_value, down_payment_percent
- client info fields (name, address, phone, email, signatories)
- agency info fields (signatories)
- file_path (for type=file)

// Key relationships
- deal() → belongsTo

// Business rules
- Auto-fill from Deal selection (client info, products as SOW)
- ContractTemplateService generates HTML for type=editor
```

#### Expense (expenses table)
```php
// Key fields
- id, user_id (submitter), project_id
- submission_date, keterangan_expense, amount
- status (Pending, Disetujui, Ditolak, Dibayarkan, Selesai)
- approved_by, paid_by, paid (amount paid)
- attachment (invoice), attachment_pay (payment proof)
- reference, rejection_reason

// Key relationships
- user() → belongsTo (submitter)
- paidBy() → belongsTo (paid_by)
- approvedBy() → belongsTo (approved_by)
- project() → belongsTo
- itemExpenses() → hasMany

// Business rules
- Director/Finance/Super Admin can see all
- Others see only own expenses
- Budget deduction on payment
```

#### Advance (advances table)
```php
// Key fields
- id, user_id, project_id
- submission_date, keterangan_advance, amount
- status, paid, paid_by, reference
- attachment, attachment_pay
- total_harga
- rejection_reason

// Relationships
- user(), paidBy(), project()
- itemAdvance() → hasMany

// Business rules
- Same approval flow as Expense
- Realisasi tracking for items
```

#### Absence (absences table)
```php
// Key fields
- id, user_id (employee)
- category (cuti, wfa, tukar_hari, sakit)
- description, start_time, end_time
- approval_status (pending, approved, rejected)
- approved_by, sick_letter (file), work_report
- rejection_reason

// Relationships
- user() → belongsTo
- approvedBy() → belongsTo

// SoftDeletes: yes

// Business rules
- Sick letter required for sakit category
- Work report required for wfa after approval
- Only Head/Director/Finance can approve
```

#### Budget (budgets table)
```php
// Key fields
- id, name, description, amount

// Relationships
- logs() → hasMany (BudgetLog)
```

#### SocialAccount (social_accounts table)
```php
// Key fields
- id, client_id (FK to Deal)
- platform (instagram, facebook, tiktok)
- platform_account_id, username, page_name
- profile_picture_url, followers_count, following_count, media_count, fan_count
- access_token (encrypted cast)
- is_active, last_synced_at, sync_status, last_sync_error
- token_expires_at, sync_metadata

// SoftDeletes: yes

// Relationships
- client() → belongsTo (Deal)
- insights() → hasMany
- posts() → hasMany
- syncLogs() → hasMany

// Key helpers
- hasValidToken(), isInstagram(), isFacebook(), isMeta()
- scopes: active(), platform(), needsSync(), failed(), syncable()
```

---

## 4. BUSINESS RULES

### 4.1 Lead Conversion Flow
```
1. Lead created with status (Dalam Komunikasi, etc.)
2. User clicks "Convert" action
3. Modal asks for: alamat, NPWP, deal_status
4. Creates Deal with:
   - All lead data copied
   - products attached from lead
   - lead.is_converted = true
   - lead.status = 'Converted'
```

### 4.2 Quotation Flow
```
1. Create Quotation → UUID auto-generated (#DG/YYMM/XXXXX)
2. Select nama_perusahaan → auto-fills telephone, nama_pic, email from Leads
3. Add items (manual or select from Product)
4. Calculate totals (subtotal, discount, VAT, grand_total)
5. Status: Pending → Approved/Rejected
6. Convert to Invoice:
   - Creates Invoice (type=estimate)
   - Copies all items to InvoicesItem
   - Updates quotation.status = 'Converted'
```

### 4.3 Expense Approval Flow
```
1. User creates expense (amount, project, items)
2. Status = 'Pending'
3. Director/Finance can:
   - Approve → Status = 'Disetujui'
   - Reject → Status = 'Ditolak', requires rejection_reason
4. After approved:
   - User uploads invoice attachment
   - Director/Finance clicks "Bayarkan"
   - Enter: paid amount, reference, payment proof, budget_id
   - Budget.amount decreases by paid amount
   - Status = 'Dibayarkan'
5. Optional: Mark as 'Selesai'
```

### 4.4 Absence Workflow
```
1. User creates absence (category: cuti/wfa/tukar_hari/sakit)
2. For sakit: upload sick_letter (image/PDF, max 2MB)
3. Status = 'pending'
4. Head/Director/Finance can:
   - Approve → Status = 'approved', approved_by set
   - Send notification to user
   - Reject → Status = 'rejected', requires rejection_reason
5. For WFA after approval:
   - User must fill work_report
```

### 4.5 Contract Generation
```
1. Select deal → auto-fills client info + products as scope_of_work
2. Set contract details (platform, content_count, revisions, duration)
3. Set dates (effective_date, expiration_date auto-calculated)
4. Set pricing (total_value, down_payment_percent)
5. Set signatories (client & agency names/titles)
6. Save with type='editor' or type='file'
7. Preview/Download PDF available
```

### 4.6 Task Assignment
```
1. Create task via Kanban or Task Resource
2. Assign users (multiple allowed)
3. On assignment:
   - TaskObserver triggers notification
   - NotifHelper sends to each assigned user
   - Message includes: task name, client, deadline
4. Kanban shows tasks based on user role:
   - Director/Admin: all tasks
   - Others: only assigned tasks
```

---

## 5. AUTHORIZATION RULES

### 5.1 Role Hierarchy
```
Super Admin
    │
    ├── Director ───────────────┐
    │                           │
    ├── Head                    │
    │                           │
    ├── Finance ───────────────┼──► Full Finance Access
    │                           │
    └── Staff ──────────────────┘
              └── Own Data Only
```

### 5.2 Access Matrix

| Resource | Director | Finance | Head | Staff |
|----------|----------|---------|------|-------|
| All Expenses | ✓ | ✓ | ✗ | Own only |
| All Advances | ✓ | ✓ | ✗ | Own only |
| All Absences | ✓ | ✓ | ✓ | Own only |
| All Tasks | ✓ | ✗ | ✓ | Assigned |
| Analytics | ✓ | ✓ | ✗ | ✗ |
| User Management | ✓ | ✗ | ✗ | ✗ |

### 5.3 Permission Structure (Spatie)
```
view_any_<model>
view_<model>
create_<model>
update_<model>
delete_<model>
restore_<model>
force_delete_<model>
```

---

## 6. API ROUTES

### 6.1 Public Routes
```php
// Quotation
GET  /quotations/{quotation}            → QuotationPdfController@show
GET  /quotations/{quotation}/download → QuotationPdfController@download

// Invoice
GET  /invoices/{invoice}/download     → InvoicePdfController@download

// Contract
GET  /contracts/{contract}/preview     → ContractPdfController@preview
GET  /contracts/{contract}/download-pdf → ContractPdfController@downloadPdf

// PWA
GET  /manifest.json                   → PWA manifest JSON

// OAuth
GET  /auth/instagram                  → InstagramConnectController@redirect
GET  /auth/instagram/callback         → InstagramConnectController@callback

// Admin redirect
GET  /admin/{any}                     → redirect to login (guest only)
```

### 6.2 Authenticated Routes (middleware: auth)
```php
// WhatsApp
POST /send-whatsapp                   → WablasController@sendMessage

// Timeline
POST /timeline/update                 → Update ProjectTimeline

// Connected Accounts API
POST /api/connected-accounts/{id}/sync          → sync
POST /api/connected-accounts/{id}/disconnect   → disconnect
POST /api/connected-accounts/{id}/refresh-token → refreshToken
```

---

## 7. SERVICES

### 7.1 ContractTemplateService
```php
// Location: app/Services/ContractTemplateService.php

generate(Contract $contract): string
- Generates HTML contract from contract data
- Uses GeneralSettings for agency branding
- Builds SOW table from deal products
- Calculates payment terms (DP %, remaining %)
- Includes signature sections
- Color theming based on settings

Helper methods:
- buildSowTable($products, $scopeOfWork): string
- darkenColor($hex, $percent): string
- lightenColor($hex, $percent): string
```

### 7.2 MetricsEngine
```php
// Location: app/Services/MetricsEngine.php

generateSnapshot(): array
- Returns financial & sales metrics for current month

Output structure:
{
  period: { start, end, week_start },
  finance: {
    revenue_received,    // actual money in
    billed_total,        // total invoiced
    expenses,            // total expenses
    profit,              // revenue - expenses
    gross_margin,        // percentage
    proforma_value,      // estimate total
    dp_coverage_percent, // DP paid / total
    burn_rate_weekly,
    cash_runway_weeks,
    overdue_invoices
  },
  sales: {
    leads, converted,
    conversion_rate,
    quotations, deals,
    lead_to_quote_rate,
    quote_to_deal_rate,
    avg_deal_size
  },
  risk_score: 0-100
}
```

### 7.3 Other Services

| Service | Purpose |
|---------|---------|
| InstagramService | Sync Instagram profile/posts/insights |
| FacebookPageService | Sync Facebook Page data |
| MetaAdsService | Sync Meta Ad accounts |
| InstagramSyncService | Connected account management |
| PushNotificationService | Web push notifications |
| GeminiService | AI analysis via Gemini API |
| WablasService | WhatsApp messaging |
| TrendEngine | Historical trends & forecasting |
| FunnelIntelligenceEngine | CRM funnel metrics |
| FileService | Secure file read/write |

---

## 7B. INVOICE TEMPLATE SYSTEM

### 7B.1 Invoice PDF Flow
```
ManageGeneral.php (Settings Page)
         ↓
    [Save to DB]
         ↓
GeneralSettings.php (inv_* fields)
         ↓
InvoicePdfController.php
         ↓
    [getInvoiceSettings()]
         ↓
invoice-dynamic.blade.php (PDF Template)
         ↓
    [Barryvdh DomPDF]
         ↓
      PDF Output
```

### 7B.2 Invoice PDF Template Structure
```blade
<!-- resources/views/pdf/invoice-dynamic.blade.php -->

<!-- Header Section -->
┌────────────────────────────────────────────────────────┐
│  INVOICE                           [LOGO]                │
│  (theme_color: {{ $themeColorSafe }})                   │
└────────────────────────────────────────────────────────┘

<!-- Company Info Section -->
┌─────────────────────┬─────────────────────────────┐
│ From:               │ To:                       │
│ {{ settings.company_info.name }}    │ {{ invoice.nama_perusahaan }} │
│ {{ settings.company_info.address }}  │ {{ invoice.alamat }}       │
│ {{ settings.company_info.phone }}    │ {{ invoice.telephone }}    │
└─────────────────────┴─────────────────────────────┘

<!-- Invoice Details -->
┌────────────────────────────────────────────────┐
│ Number: {{ invoice.uuid }}                      │
│ Date: {{ invoice.date }}                        │
│ Due Date: {{ invoice.due_date }}                │
│ Type Payment: {{ invoice.type_payment }}        │
│ PO Number: {{ invoice.po_number }}              │
│ No. Faktur: {{ invoice.faktur }}               │
└────────────────────────────────────────────────┘

<!-- Items Table -->
┌────────────────────────────────────────────────────┐
│ Item │ Description │ Unit │ Rate │ Disc │ Price │
│──────────────────────────────────────────────────│
│ ...items...                                      │
└────────────────────────────────────────────────────┘

<!-- Summary & Payment -->
┌────────────────────────────────────────────────────┐
│ PAYMENT DETAILS          │ Sub Total: RpXXX          │
│ BANK {{ bank_name }} {{ account }}  │ Discount: RpXXX        │
│ a/n {{ account_name }}  │ VAT: RpXXX                │
│                         │ TOTAL: RpXXX              │
└────────────────────────────────────────────────────┘

<!-- Signature (sale type only) -->
┌────────────────────────────────────────────────────┐
│                                     sincerely,     │
│                                     [SIGNATURE]   │
│                                     {{ signature_name }}│
└────────────────────────────────────────────────────┘
```

### 7B.3 Settings Locations

| File | Purpose |
|------|---------|
| `app/Settings/GeneralSettings.php` | Property definitions & defaults |
| `app/Filament/Pages/Setting/ManageGeneral.php` | UI form untuk settings |
| `database/settings/2024_02_20_131300_create_general_settings.php` | Migration untuk default values |
| `resources/views/pdf/invoice-dynamic.blade.php` | PDF template |
| `app/Http/Controllers/InvoicePdfController.php` | PDF generation controller |

---

## 8. SETTINGS (GeneralSettings)

### 8.1 Configuration Schema
```php
// Location: app/Settings/GeneralSettings.php

// Brand
brand_name: string
brand_logo: ?string (file path)
brand_logoHeight: string
site_active: bool
site_favicon: ?string
site_theme: array (primary, etc.)

// Event Categories
event_categories: [
  { color: string, label: string },
  ...
]

// Quotation Template
quot_show_cover_pages: bool
quot_cover_page_1, _2, _3: ?string (image paths)
quot_theme_color: string (hex, default: #f04f29)
quot_logo: ?string
quot_company_name: string
quot_company_email: string
quot_company_phone: string
quot_signature_name: string
quot_signature_title: string
quot_signature_image: ?string
quot_terms: string (multiline)

// Invoice Template
inv_theme_color: string
inv_logo: ?string
inv_company_name: string
inv_company_address: string
inv_company_phone: string
inv_bank_name: string
inv_bank_account: string
inv_bank_account_name: string
inv_signature_name: string
inv_signature_image: ?string
```

---

## 9. ENUMS

### 9.1 TaskStages
```php
// Location: app/Enums/TaskStages.php

cases: Log, Process, Review, Done, Hold
```

### 9.2 EventCategory
```php
// Location: app/Enums/EventCategory.php

cases: (based on settings event_categories)
```

---

## 10. HELPERS

### 10.1 ColorHelper
```php
// Location: app/Helpers/ColorHelper.php

status(string $status): string
- Maps status to Filament color (warning, success, danger, info, gray)

absenceCategory(string $category): string
- Maps absence category to color

dealStatus(string $status): string
leadsStatus(string $status): string
taskStatus(string $status): string
quotationStatus(string $status): string
projectStatus(string $status): string
tokenStatus(bool $isExpired, int $days): string
performanceScore(float $score): string
contractType(string $type): string
```

### 10.2 NotifHelper
```php
// Location: app/Helpers/NotifHelper.php

send(User $user, string $title, string $body, string $icon, string $color, ?string $url)
- Sends notification to user
- Also sends push notification if enabled
```

---

## 11. OBSERVERS

### 11.1 List
- ExpenseObserver
- AdvanceObserver
- AbsenceObserver
- BudgetObserver
- TaskObserver
- AnnouncementObserver

### 11.2 Example: TaskObserver
```php
// Triggers notification when task is created/updated
// Uses NotifHelper to send to assigned users
```

---

## 12. CUSTOM PAGES

### 12.1 Dashboard (`/admin`)
- Shows: Announcements, TaskToday, Calendar, TaskWidget
- Widgets via getHeaderWidgets() and getFooterWidgets()

### 12.2 AnalyticsDashboard (`/admin/analytics-dashboard`)
- Header: FinancialAnalyzer widget
- Footer: InvoiceStats, RevenueChart, StatusChart, TopExpenses, RecentInvoices

### 12.3 TaskKanbanBoard (`/admin/task-kanban-board`)
- Uses Mokhosh\FilamentKanban\Pages\KanbanBoard
- Columns: Log, Process, Review, Done, Hold
- Custom edit modal (slide over)
- Create form with tabs

### 12.4 ProjectTimelineBoard (`/admin/project-timeline-board`)
- Gantt-style timeline for project phases

### 12.5 PwaSettings (`/admin/pwa-settings`)
- Push notification configuration

---

## 13. KNOWN TECHNICAL DECISIONS

### 13.1 UUID Formats
```
Quotation: #DG/YYMM/XXXXX  (e.g., #DG/0526/00001)
Invoice:   #PI/YYMMDD/XXXXX (e.g., #PI/260526/00001)
```

### 13.2 Token Encryption
- SocialAccount: uses `protected $casts = ['access_token' => 'encrypted']`
- ConnectedAccount: uses `Crypt::encryptString()` / `Crypt::decryptString()`

### 13.3 Soft Deletes
- Absence: uses SoftDeletes
- SocialAccount: uses SoftDeletes

### 13.4 Tax Calculation
- Product stores pajak as array (e.g., [11] for PPN 11%)
- Total auto-calculated: harga + (harga * sum(pajak) / 100)

### 13.5 Deal-Invoice Matching
- Invoices matched to Deal by `nama_perusahaan` field (not foreign key)

### 13.6 Role Check Patterns
```php
// Finance access
$user->hasRole(['Director', 'Finance', 'super_admin'])

// Task visibility
Task::whereHas('users', fn($q) => $q->where('user_id', $user->id))
```

---

## 14. TESTING CHECKLIST

When modifying code, verify:
- [ ] UUID generation still works (no duplicates)
- [ ] Token encryption/decryption not broken
- [ ] Role-based access still enforced
- [ ] Observers still trigger notifications
- [ ] PDF generation (quotation, contract, invoice)
- [ ] ColorHelper mappings still correct
- [ ] Form auto-fill from Deal/Leads
- [ ] Budget deduction on expense payment
- [ ] Soft delete cascade (if applicable)

---

## 15. COMMON ISSUES & SOLUTIONS

| Issue | Solution |
|-------|----------|
| Quotation UUID duplicates | Check Quotation::generateUuid() logic, add unique index |
| Token decryption fails | Check if token was encrypted with correct key (app key changed?) |
| Push notifications not working | Check VAPID keys, user push_subscription |
| PDF not generating | Check DomPDF configuration, CSS compatibility |
| KPI metrics wrong | Check MetricsEngine date ranges and aggregation |

---

## 16. UI/THEMING

### 16.1 Typography - Font Sora

**Font**: Sora (Google Fonts)
**Weights**: 100, 200, 300, 400, 500, 600, 700, 800

**Files Modified**:
- `resources/css/app.css` - Import & global apply
- `resources/css/filament/admin/theme.css` - Filament theme
- `resources/css/filament-clickup.css` - ClickUp style overrides
- `resources/views/pdf/invoice-dynamic.blade.php` - PDF template

**CSS Implementation**:
```css
/* Font Sora - Import */
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@100;200;300;400;500;600;700;800&display=swap');

/* Apply to all elements */
* {
    font-family: 'Sora', sans-serif !important;
}
```

**Applied To**:
- Sidebar navigation
- Topbar / Header
- Form inputs (text, select, textarea)
- Tables (headers, cells)
- Buttons
- Cards
- Badges
- Modals
- Notifications
- Invoice PDF
- Quotation PDF

### 16.2 CSS Files Structure

```
resources/css/
├── app.css                    # Main Tailwind entry point
├── filament/
│   └── admin/
│       ├── theme.css          # Filament admin theme (Sora)
│       └── tailwind.config.js  # Tailwind configuration
├── filament-clickup.css       # ClickUp style overrides
├── clickup.css                # Legacy styles
└── pwa.css                    # PWA styles
```

### 16.3 Theme Colors (Default)

| Color | Hex | Usage |
|-------|-----|-------|
| Primary | #f04f29 | Main brand color |
| Success | #22c55e | Positive states |
| Warning | #eab308 | Warning states |
| Danger | #ef4444 | Error/danger states |
| Info | #3b82f6 | Info states |

---

## 17. FILE NAMING CONVENTIONS

| Type | Convention | Example |
|------|------------|---------|
| Model | PascalCase, singular | `User.php`, `Project.php` |
| Resource | PascalCase + Resource | `DealResource.php` |
| Service | PascalCase + Service | `MetricsEngine.php` |
| Helper | PascalCase + Helper | `ColorHelper.php` |
| Observer | PascalCase + Observer | `ExpenseObserver.php` |
| Controller | PascalCase + Controller | `QuotationPdfController.php` |
| Page | PascalCase (no suffix) | `AnalyticsDashboard.php` |
| Widget | PascalCase + Widget | `InvoiceStatsOverview.php` |

---

## 18. GIT WORKFLOW

### 18.1 Commit History Format
```
feat: New feature description
fix: Bug fix description
docs: Documentation update
style: UI/styling changes
refactor: Code refactoring
perf: Performance improvements
test: Test updates
chore: Maintenance tasks
```

### 18.2 Recent Commits
- `9036457` - Add dynamic quotation PDF template with customizable settings
- `8779aab` - Add foto field to Asset for product photos
- `7c09f9d` - Fix ContractTemplateService: return statement, signature layout, PDF colors

### 18.3 Branch Strategy
- `main` - Production-ready code
- Feature branches from main for development

---

## 19. QUICK REFERENCE FOR AI

### Key Patterns
```php
// Font Sora (CSS)
* { font-family: 'Sora', sans-serif !important; }

// Invoice Settings
$settings = app(GeneralSettings::class);
$settings->inv_theme_color;
$settings->getInvoiceSettings();

// Role Check
$user->hasRole(['Director', 'Finance', 'super_admin'])

// UUID Format
#DG/YYMM/XXXXX (Quotation)
#PI/YYMMDD/XXXXX (Invoice)

// Token Encryption
'access_token' => 'encrypted' // in $casts
```

### Important File Locations
```
/admin/settings/general     → ManageGeneral settings page
/admin/analytics-dashboard   → Financial analytics
/admin/task-kanban-board    → Task kanban
/admin/project-timeline-board → Project timeline

resources/views/pdf/invoice-dynamic.blade.php
app/Settings/GeneralSettings.php
app/Filament/Pages/Setting/ManageGeneral.php
```

---

**End of PRD**