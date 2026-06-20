# PRD - ERP Creative
## Product Requirements Document for Digital Agency Management System

**Version**: 3.1
**Last Updated**: 2026-06-20
**Company**: ERP Creative
**Type**: Business Management ERP for Digital Marketing Agency

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

### 2.2 Finance Flow

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   ADVANCE   │────▶│  DISETUJUI  │────▶│ DIBAYARKAN  │────▶│  SELESAI    │
│  (Uang Muka)│     │  (Approved) │     │ (Paid)      │     │ (Completed) │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘

┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   EXPENSE   │────▶│  DISETUJUI  │────▶│ DIBAYARKAN  │────▶│  SELESAI    │
│ (Pengeluaran│     │  (Approved) │     │ (Paid)      │     │ (Completed) │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
```

### 2.3 Payment Account System [v2.2]

```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  PAYMENT_ACCOUNT │     │   TRANSACTION    │     │     INVOICE      │
├──────────────────┤     ├──────────────────┤     ├──────────────────┤
│ id               │◀─── │ id               │     │ id               │
│ name             │     │ account_id       │     │ payment_account  │
│ type             │     │ type (in/out)    │────▶│ paid             │
│ account_number   │     │ amount           │     │ status           │
│ bank_name        │     │ reference        │     └──────────────────┘
│ amount           │     └──────────────────┘
│ description      │
│ is_active        │
└──────────────────┘
```

---

## 3. Modul & Fitur

### 3.1 CRM Module

#### 3.1.1 Leads
- **Fields**: nama_pic, nama_perusahaan, email, telephone, source, status, product_id
- **Source**: Website, Social Media, Email Marketing, Event, Referral, Partner
- **Status**: Dalam Komunikasi, Proposal Sudah Dikirim, Menang, Kalah, Converted
- **Fitur**:
  - Convert to Deal (otomatis copy data + attach products)
  - Multi-product association
  - Status change tracking
  - is_converted flag

#### 3.1.2 Deal (Client)
- **Fields**: nama_perusahaan, nama_pic, email, telephone, NPWP, alamat, notes, status
- **Status**: Negosiasi, Menunggu Pembayaran, Dalam Tahap Project, Selesai, Hold
- **Relations**: Invoices, Quotations, Projects, Tasks, Contracts
- **Fitur**:
  - Auto-populate dari Lead saat convert
  - Multi-product association
  - Social account link
  - Meta Ad account link

#### 3.1.3 Product/Layanan
- **Fields**: nama_layanan, detail_layanan, harga_layanan, pajak (PPN 11-12%), total_harga
- **Fitur**: Auto-calculate total with tax

#### 3.1.4 Quotation
- **UUID Format**: `#DG/YYMM/XXXXX` (e.g., #DG/0626/00001)
- **Fields**: uuid, nama_perusahaan, telephone, email, date, due_date, items
- **Item Fields**: product_name, description, qty, price, discount, vat_percentage, vat, total
- **Status**: Pending, Approved, Rejected, Converted
- **Fitur**:
  - Auto UUID generation
  - Convert to Proforma Invoice
  - Download PDF
  - Auto-fill dari Leads (nama_perusahaan selection)

#### 3.1.5 Contract
- **Type**: editor (generated HTML) atau file (uploaded PDF)
- **Fields**: deal_id, type, scope_of_work, platform, content_count, max_revisions, duration_months, effective_date, expiration_date, total_value, down_payment_percent, signatures
- **Fitur**:
  - Auto-generate HTML dari deal data (ContractTemplateService)
  - PDF download
  - Signature fields
  - SOW table dari products

### 3.2 Project Management Module

#### 3.2.1 Project
- **Fields**: deal_id, nama_project, detail_project, timeline_mulai, timeline_selesai, nama_perusahaan, alamat, nama_pic, notes
- **Categories**: Social Media Management, Video Production, SEO, TikTok Handling, Website Development, Live TikTok Sessions, Photo Product
- **Fitur**:
  - Wizard form dengan auto-fill dari Deal
  - Deadline calculation
  - Client info auto-populate

#### 3.2.2 Task
- **Fields**: nama_task, project_id, deal_id, detail_task, mulai_task, selesai_task, status, stage_key, proritas, nama_layanan, creator_id, users (many-to-many)
- **Status (TaskStages)**: Database-backed configuration dengan TaskStage model
  - Default stages: Log, Process, Review, Done, Hold
  - Each stage has: key, name, color, background_color, sort_order, is_active
- **Priority**: Urgent, Normal, Low
- **Fitur**:
  - Kanban Board (Mokhosh/FilamentKanban)
  - **Dynamic Task Stages** - Admin bisa tambah, edit, hapus, reorder stages via UI
  - Multi-user assignment
  - Notification on assignment (TaskObserver)
  - Performance tracking

#### 3.2.3 TaskStages (Database Configuration)
- **Table**: `task_stages`
- **Fields**:
  - `key` - Unique identifier (e.g., log, process, review)
  - `name` - Display name in UI (customizable)
  - `color` - Hex color for accent/header
  - `background_color` - Hex color for column background
  - `sort_order` - Order in kanban board
  - `is_active` - Enable/disable stage
- **Access**: Settings → Task Stages
- **Fitur**:
  - CRUD operations via Filament Resource
  - Drag-drop reorder
  - Delete protection (can't delete if tasks assigned)
  - Cache-backed for performance

### 3.3 Finance Module

#### 3.3.1 Expense
- **Fields**: user_id, submission_date, project_id, keterangan_expense, amount, status, attachment, paid, paid_by, reference, payment_account_id
- **ItemExpenses**: name, description, price, quantity, subtotal
- **Status**: Pending, Disetujui, Ditolak, Dibayarkan, Selesai
- **Fitur**:
  - Role-based visibility (Director/Finance: all, others: own only)
  - Upload invoice attachment
  - Payment processing dengan PaymentAccount deduction
  - Approval workflow
  - Payment proof upload (attachment_pay)

#### 3.3.2 Advance
- **Fields**: user_id, submission_date, project_id, keterangan_advance, amount, status, paid, paid_by, payment_account_id
- **ItemAdvance**: detail items
- **Status**: Pending, Disetujui, Ditolak, Dibayarkan, Selesai
- **Fitur**: Realisasi tracking, approval workflow

#### 3.3.3 PaymentAccount [v2.2]
- **Fields**: name, type (cash, bank, ewallet), account_number, bank_name, amount, description, is_active
- **Relations**: hasMany Transaction (polymorphic)
- **Fitur**:
  - Unified payment tracking
  - Auto-balance update via PaymentAccountObserver
  - Links to Invoice, Expense, Advance

#### 3.3.4 Transaction [v2.2]
- **Fields**: payment_account_id, transactionable_type, transactionable_id, type (income/expense), amount, description, reference, transaction_date, created_by
- **Polymorphic**: Links to Invoice, Expense, Advance

#### 3.3.5 Invoice (via TomatoPHP)
- **UUID Format**: `#PI/YYMMDD/XXXXX` (e.g., #PI/260611/00001)
- **Types**: Sale, Estimate (Proforma)
- **Fields**: uuid, type, payment_account_id, grand_total, paid, status
- **Fitur**: 
  - UUID generation
  - Item management
  - Payment tracking (links to PaymentAccount)
  - Download PDF
  - Customizable template via GeneralSettings

#### 3.3.6 Analytics Dashboard
- **Financial Metrics**:
  - Revenue Received (actual money in)
  - Billed Total (total invoiced)
  - Expenses
  - Profit (revenue - expenses)
  - Gross Margin (percentage)
  - Burn Rate Weekly
  - Cash Runway Weeks
  - Overdue Invoices
- **Sales Funnel**:
  - Leads Count
  - Conversion Rate
  - Quotations
  - Deals
  - Lead to Quote Rate
  - Quote to Deal Rate
  - Average Deal Size
- **Risk Score (0-100)**:
  - Margin < 20% → +20
  - Conversion < 15% → +20
  - Runway < 4 weeks → +30
  - DP Coverage < 20% → +20
  - Overdue invoices → +10

### 3.4 HR Module

#### 3.4.1 Employee
- **Fields**: nik, firstname, lastname, email, phone, address, position, npwp, ktp, file_npwp, division
- **Divisions**: Social Media Specialist, Finance, HR, Graphic Design, Video Editor
- **Positions**: Staff, Supervisor, Manager, Director

#### 3.4.2 Absence
- **Fields**: user_id, category, description, start_time, end_time, approval_status, approved_by, work_report, sick_letter, rejection_reason
- **Categories**: cuti, wfa (Work From Anywhere), tukar_hari, sakit
- **Status**: pending, approved, rejected
- **Fitur**:
  - Sick letter upload (for sakit category, max 2MB)
  - Work report for WFA after approval
  - Approval workflow with notifications
  - Role-based visibility (Director/Finance/Head: manage all, others: own only)
  - SoftDeletes

#### 3.4.3 Event/Calendar
- **Fields**: title, description, start_time, end_time, color, create_by
- **Fitur**: Event categories dengan colors (configurable via GeneralSettings)

### 3.5 Tools & Integrations

#### 3.5.1 Social Account
- **Platforms**: Instagram, Facebook, TikTok
- **Fields**: client_id (Deal FK), platform, platform_account_id, username, page_name, access_token (encrypted), followers_count, following_count, media_count, fan_count, is_active, last_synced_at, sync_status
- **Fitur**:
  - Auto token encryption
  - Profile/Posts/Insights sync
  - Instagram OAuth integration
  - Sync status tracking
  - Token expiry monitoring

#### 3.5.2 Meta Ad Account
- **Fields**: client_id (Deal FK), ad_account_id, account_name, access_token, token_expires_at, is_active
- **Fitur**: Campaign metrics tracking

#### 3.5.3 Connected Account
- **Fields**: user_id, provider, account_id, username, profile_picture, access_token (encrypted)
- **Fitur**: Per-user social account connection

---

## 4. Data Models

### 4.1 Core Models

| Model | Table | Key Relationships |
|-------|-------|-------------------|
| **User** | users | hasMany: Task, Expense, Advance, Absence, Event, Asset, PurchaseRequest; belongsToMany: Task |
| **Leads** | leads | belongsToMany: Product; hasOne: Deal |
| **Deal** | deals | belongsToMany: Product; hasMany: Project, Invoice, Quotation, Contract, SocialAccount, MetaAdAccount |
| **Product** | products | belongsToMany: Leads, Deal |
| **Project** | projects | belongsTo: Deal; hasMany: Task, ProjectTimeline |
| **Task** | tasks | belongsToMany: User; belongsTo: Project, Deal |

### 4.2 Finance Models

| Model | Table | Relationships |
|-------|-------|---------------|
| **Expense** | expenses | belongsTo: User (submitter, paid_by, approved_by), Project, PaymentAccount; hasMany: ItemExpense |
| **ItemExpense** | item_expenses | belongsTo: Expense |
| **Advance** | advances | belongsTo: User, Project, PaymentAccount; hasMany: ItemAdvance |
| **ItemAdvance** | item_advances | belongsTo: Advance |
| **PaymentAccount** | payment_accounts | hasMany: Transaction |
| **Transaction** | transactions | morphTo: transactionable (Invoice, Expense, Advance) |

### 4.3 CRM Models

| Model | Table | Relationships |
|-------|-------|---------------|
| **Quotation** | quotations | hasMany: QuotationItem |
| **QuotationItem** | quotation_items | belongsTo: Quotation |
| **Contract** | contracts | belongsTo: Deal |
| **PurchaseRequest** | purchase_requests | belongsTo: User |
| **Asset** | assets | belongsTo: User (penanggung_jawab) |

### 4.4 Social Media Models

| Model | Table | Relationships |
|-------|-------|---------------|
| **SocialAccount** | social_accounts | belongsTo: Deal; hasMany: SocialPost, SocialAccountInsight |
| **SocialPost** | social_posts | belongsTo: SocialAccount; hasOne: SocialPostMetric |
| **SocialAccountInsight** | social_account_insights | belongsTo: SocialAccount |
| **MetaAdAccount** | meta_ad_accounts | belongsTo: Deal; hasMany: MetaCampaignMetric |
| **ConnectedAccount** | connected_accounts | belongsTo: User |

---

## 5. Authorization

### 5.1 Role Hierarchy

```
Super Admin
    │
    ├── Director ───────────────┐
    │                           │
    ├── Head                    │
    │                           │
    ├── Finance ────────────────┼──► Full Finance Access
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

### 5.3 Role Check Pattern

```php
// Finance access
$user->hasRole(['Director', 'Finance', 'super_admin'])

// Task visibility
Task::whereHas('users', fn($q) => $q->where('user_id', $user->id))
```

---

## 6. Services

### 6.1 ContractTemplateService
- Generate HTML contract dari Deal data
- Builds SOW table dari products
- Calculates payment terms (DP %, remaining %)
- Includes signature sections
- Color theming based on GeneralSettings

### 6.2 MetricsEngine
- Financial snapshot (revenue, expenses, profit, margin)
- Sales funnel metrics (leads, conversions)
- Risk score calculation
- Cash runway analysis

### 6.3 Social Services

| Service | Purpose |
|---------|---------|
| InstagramService | Sync Instagram profile/posts/insights |
| FacebookPageService | Sync Facebook Page data |
| MetaAdsService | Sync Meta Ad accounts |
| InstagramSyncService | Connected account management |

### 6.4 Other Services

| Service | Purpose |
|---------|---------|
| PushNotificationService | Web push notifications (PWA) |
| GeminiService | AI analysis via Gemini API |
| WablasService | WhatsApp messaging |
| TrendEngine | Historical trends & forecasting |
| FunnelIntelligenceEngine | CRM funnel metrics |
| FileService | Secure file operations |
| NavigationHelper | Dynamic navigation label helper |

---

## 7. Settings

### 7.1 GeneralSettings Schema

```php
// Brand
- brand_name, brand_logo, brand_logoHeight
- site_active, site_favicon
- site_theme (primary, etc.)
- primary_gradient

// Event Categories
- event_categories: [{color, label}, ...]

// Quotation Template
- quot_show_cover_pages
- quot_cover_page_1, _2, _3 (images)
- quot_theme_color, quot_logo
- quot_company_name, quot_company_email, quot_company_phone
- quot_signature_name, quot_signature_title, quot_signature_image
- quot_terms

// Invoice Template
- inv_theme_color, inv_logo
- inv_company_name, inv_company_address, inv_company_phone
- inv_bank_name, inv_bank_account, inv_bank_account_name
- inv_signature_name, inv_signature_image

// Navigation Labels (v3.1)
- nav_leads, nav_client, nav_product, nav_quotation, nav_contract
- nav_project, nav_task_kanban, nav_task_list, nav_timeline
- nav_expense, nav_advance, nav_payment_account, nav_invoice, nav_analytics
- nav_employee, nav_absence, nav_event
- nav_social_account, nav_connected_account, nav_meta_ad
- nav_user_management, nav_general_settings, nav_mail_settings, nav_pwa_settings

// Footer Settings (v3.1)
- footer_show_brand, footer_brand_text
- footer_show_version, footer_show_copyright
- footer_copyright_text, footer_custom_text

// PWA
- pwa_name, pwa_short_name, pwa_background_color, pwa_theme_color
- vapid_public_key, vapid_private_key
```

### 7.2 Navigation Labels [v3.1]

Admin dapat custom nama menu di sidebar melalui **Settings → General Settings → Tab Navigation**.

| Section | Menu Items |
|---------|------------|
| **CRM** | Leads, Client, Product, Quotation, Contract |
| **Project** | Project, Task Kanban, Task List, Timeline |
| **Finance** | Expense, Advance, Payment Account, Invoice, Analytics |
| **HR** | Employee, Absence, Event |
| **Tools** | Social Account, Connected Account, Meta Ad Account |
| **Access & Settings** | User Management, General Settings, Mail Settings, PWA Settings |

### 7.3 Footer Settings [v3.1]

Admin dapat customize footer aplikasi melalui **Settings → General Settings → Tab Footer**.

| Setting | Description |
|---------|-------------|
| `footer_show_brand` | Toggle menampilkan brand name |
| `footer_brand_text` | Override teks brand (kosong = app name) |
| `footer_show_version` | Toggle menampilkan versi app |
| `footer_show_copyright` | Toggle menampilkan copyright |
| `footer_copyright_text` | Teks copyright (default: All Rights Reserved.) |
| `footer_custom_text` | Teks tambahan di tengah footer |

---

## 8. UI/UX Standards

### 8.1 Typography - Font Inter
- **Font**: Inter (Google Fonts)
- **Weights**: 400, 500, 600, 700, 800

### 8.2 Color Palette

| Name | Hex | Usage |
|------|-----|-------|
| Primary | #07a0cf | Main brand, CTAs |
| Primary Dark | #0589b0 | Hover states |
| Text | #0f172a | Headings |
| Text Secondary | #334155 | Body text |
| Muted | #64748b | Labels, captions |
| Border | #e2e8f0 | Cards, inputs |
| Background | #f8fafc | Page background |

### 8.3 Table Standards

```css
/* Header */
background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
font-size: 10px;
font-weight: 700;
text-transform: uppercase;
letter-spacing: 0.6px;

/* Body Row */
font-size: 12px;
color: #334155;
padding: 11px 14px;

/* Hover */
background: #f8fafc;
```

### 8.4 Component Standards

- **Badge**: border-radius: 999px (pill), font-size: 10px, font-weight: 600
- **Button**: border-radius: 8px, font-size: 12px, font-weight: 600
- **Form Input**: border-radius: 8px, border: 1px solid #e2e8f0
- **Card**: border: 1px solid #e2e8f0, border-radius: 12px

---

## 9. Navigation Structure

> **Note**: Menu labels can be customized via **Settings → General Settings → Navigation Tab** [v3.1]

```
CRM
├── Leads
├── Client (Deal)
├── Product
├── Quotation
├── Contract

Project
├── Project
├── Task Kanban Board
├── Project Timeline Board

Finance
├── Expense
├── Advance
├── Payment Account [v2.2]
├── Invoice
├── Analytics Dashboard

HR
├── Employee
├── Absence
├── Event/Calendar

Tools
├── Social Account
├── Connected Account
├── Meta Ad Account

Access
├── User Management

Settings
├── General Settings
├── Mail Settings
├── PWA Settings
├── Task Stages [v3.1]
```

---

## 10. Technical Decisions

### 10.1 UUID Formats
```
Quotation: #DG/YYMM/XXXXX  (e.g., #DG/0626/00001)
Invoice:   #PI/YYMMDD/XXXXX (e.g., #PI/260611/00001)
```

### 10.2 Token Encryption
```php
// Model cast
'access_token' => 'encrypted'

// Manual encryption/decryption
Crypt::encryptString($token)
Crypt::decryptString($token)
```

### 10.3 Soft Deletes
- Absence
- SocialAccount

### 10.4 Observers
- ExpenseObserver
- AdvanceObserver
- AbsenceObserver
- TaskObserver
- InvoiceObserver (payment tracking)
- PaymentAccountObserver (balance updates)
- SocialAccountObserver

### 10.5 Deal-Invoice Matching
- Invoices matched to Deal by `nama_perusahaan` field
- Invoices now have `payment_account_id` for payment tracking

---

## 11. Installation

```bash
# Clone repository
git clone <repository-url>
cd erp-krafty

# Install dependencies
composer install
npm install

# Copy environment file
cp .env.example .env

# Generate keys
php artisan key:generate
php artisan filament:upgrade

# Run migrations
php artisan migrate

# Create storage link
php artisan storage:link

# Clear cache
php artisan cache:clear
php artisan view:clear

# Start development server
php artisan serve
```

---

## 12. Common Commands

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

# Sync social accounts
php artisan sync:instagram
php artisan sync:meta-ads

# Check routes
php artisan route:list

# Check permissions
php artisan permission:cache-reset

# Run tests
php artisan test
```

---

## 13. Common Issues & Solutions

| Issue | Solution |
|-------|----------|
| PDF not showing | `php artisan view:clear && php artisan cache:clear` |
| Token errors | Check APP_KEY in .env (re-encrypt tokens if changed) |
| Widget not loading | Check widget class extends correctly |
| Kanban not working | Verify TaskStages database has data, run seeder if needed |
| Task Stages not showing | Run `php artisan db:seed --class=TaskStageSeeder` |
| Quotation UUID duplicates | Check generateUuid() logic, add unique index |
| Push notifications not working | Check VAPID keys, user push_subscription |
| Announcement table blank | Use `modifyQueryUsing` instead of `query` |
| Navigation labels not updating | Clear cache: `php artisan cache:clear && php artisan filament:clear-cached-components` |

---

## 14. Resource Standards

### 14.1 Table Standards
```php
public static function table(Table $table): Table
{
    return $table
        ->modifyQueryUsing(fn ($query) => $query->with(['relation']))
        ->defaultSort('created_at', 'desc')
        ->columns([...])
        ->filters([...])
        ->actions([...]);
}
```

### 14.2 Resource Checklist
- [ ] `modifyQueryUsing` (NOT `query`)
- [ ] `defaultSort` (usually created_at desc)
- [ ] Filters for status/foreign keys
- [ ] Edit/Delete with `hiddenLabel()` + `tooltip()`
- [ ] Eager loading with `with()` for relationships

---

## 15. Changelog

### v3.1 (2026-06-20)
- **Dynamic Task Stages**
  - TaskStage model - Database-backed configuration for Kanban stages
  - TaskStageResource - Filament resource for CRUD operations
  - Default stages: Log, Process, Review, Done, Hold
  - Each stage has customizable: name, color, background_color, sort_order
  - Cache-backed for performance
  - Admin can add, edit, delete, reorder stages via UI
  - Access: Settings → Task Stages

- **Customizable Navigation Labels**
  - NavigationHelper class - Helper for dynamic navigation labels
  - 25+ menu labels configurable via General Settings
  - Sections: CRM, Project, Finance, HR, Tools, Access, Settings
  - Access: Settings → General Settings → Navigation Tab

- **Customizable Footer**
  - footer_show_brand, footer_show_version, footer_show_copyright toggles
  - footer_brand_text, footer_copyright_text, footer_custom_text customization
  - Access: Settings → General Settings → Footer Tab

- **PRD Documentation Update**
  - Added TaskStages database configuration
  - Added Navigation Labels settings
  - Added Footer Settings
  - Updated navigation structure

### v2.2 (2026-06-02)
- **Budget to PaymentAccount Restructuring**
  - BudgetLog deleted - Simplified budget tracking
  - BudgetResource deleted - Removed old budget management
  - PaymentAccount created - New unified payment account system
  - Transaction model - New model for tracking all payment transactions
  - Invoice relationship - Invoices now link to PaymentAccount
  - PaymentDashboard page - New dashboard for payment overview

### v2.1 (2026-05-27)
- **UI/UX Standardization**
  - Font Inter globally
  - Table standardization
  - Card styling
  - Badge styling
  - Button styling
  - Form inputs

### v2.0 (2026-05-26)
- Invoice Template Settings - UI untuk customize invoice
- Invoice PDF Template - Dynamic PDF dengan customizable settings
- Header Layout Fix - Balance text dengan logo di invoice PDF

---

## 16. Roadmap

### Priority 1: Critical
- [ ] Database indexes (migration exists)
- [ ] Form Request validation classes
- [ ] Mass assignment protection audit

### Priority 2: Data Integrity
- [ ] Audit logging service
- [ ] Activity trail for finance resources

### Priority 3: Performance
- [ ] Model $casts optimization
- [ ] Consistent cache naming (erp:prefix)
- [ ] Query caching with laravel-model-caching

### Priority 4: Missing Features
- [ ] Excel export for all resources
- [ ] REST API endpoints (routes/api.php)
- [ ] Scheduled tasks (Console/Kernel.php)

### Priority 5: Automation
- [ ] LeadFollowUpJob
- [ ] ContractRenewalJob
- [ ] InvoiceReminderJob

### Priority 6: Testing
- [ ] Unit tests for models (80% target)
- [ ] Feature tests for workflows

### Priority 7: Integrations
- [ ] Google Analytics 4
- [ ] Accounting software sync (Jurnal/Zahir)
- [ ] Enhanced WhatsApp templates

---

**End of PRD**