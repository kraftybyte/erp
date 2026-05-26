# ERP Creative - Sistem Informasi ERP untuk Digital Agency

## 📋 Overview

**ERP Creative** adalah aplikasi ERP (Enterprise Resource Planning) berbasis Laravel yang dirancang khusus untuk **Digital Marketing Agency** - sebuah agency yang bergerak di bidang digital marketing. Aplikasi ini mengelola seluruh aspek operasional bisnis mulai dari CRM, Project Management, Finance, HR, hingga Social Media Analytics.

---

## 🏗️ Arsitektur Sistem

### Tech Stack

| Komponen | Teknologi |
|----------|-----------|
| **Backend Framework** | Laravel 11 |
| **Admin Panel** | Filament v3 |
| **Authentication** | Laravel Sanctum + Spatie Permission |
| **Database** | MySQL |
| **File Storage** | Spatie Media Library |
| **PDF Generation** | Barryvdh DomPDF |
| **Social Media API** | Meta Graph API v19 (Instagram, Facebook) |
| **AI Integration** | Google Gemini API |
| **Messaging** | Wablas WhatsApp API |
| **Push Notifications** | Web Push (VAPID) |

### Directory Structure

```
app/
├── Console/Commands/      # Artisan Commands
├── Enums/                  # Enum Classes (TaskStages, EventCategory)
├── Filament/
│   ├── Pages/              # Custom Pages (Dashboard, Analytics, Kanban)
│   ├── Resources/          # Filament Resources (CRUD)
│   └── Widgets/             # Dashboard Widgets
├── Helpers/                # Helper Classes (ColorHelper, NotifHelper)
├── Http/Controllers/       # Controllers
├── Jobs/                   # Queue Jobs
├── Livewire/               # Livewire Components
├── Models/                 # Eloquent Models
├── Notifications/          # Notification Classes
├── Observers/              # Model Observers
├── Policies/               # Authorization Policies
├── Providers/              # Service Providers
├── Services/               # Business Logic Services
└── Settings/               # Application Settings
```

---

## 📊 Modul & Fitur

### 1. CRM (Customer Relationship Management)

#### Leads Management
- **Fields**: nama_pic, nama_perusahaan, email, telephone, source, status, product_id
- **Status**: Dalam Komunikasi, Proposal Sudah Dikirim, Menang, Kalah, Converted
- **Features**:
  - Convert to Deal
  - Multi-product association
  - Status change tracking

#### Deal/Client Management
- **Fields**: nama_perusahaan, nama_pic, email, telephone, NPWP, alamat, notes, status
- **Status**: Negosiasi, Menunggu Pembayaran, Dalam Tahap Project, Selesai, Hold
- **Relations**: Invoices, Quotations, Projects, Tasks, Contracts

#### Product/Layanan
- **Fields**: nama_layanan, detail_layanan, harga_layanan, pajak (PPN 12%), total_harga
- **Features**: Auto-calculate total with tax

#### Quotation
- **Fields**: uuid (#DG/YYMM/XXXXX), nama_perusahaan, telephone, email, date, due_date, items
- **Item Fields**: product_name, description, qty, price, discount, vat_percentage, vat, total
- **Features**:
  - Auto UUID generation
  - Convert to Proforma Invoice
  - Download PDF
  - Auto-fill from Leads

#### Contract
- **Fields**: deal_id, type (editor/file), scope_of_work, platform, content_count, max_revisions, duration_months, effective_date, expiration_date, total_value, down_payment_percent, signatures
- **Features**:
  - Auto-generate HTML from deal data
  - PDF download
  - Signature fields

### 2. Project Management

#### Project
- **Fields**: deal_id, nama_project, detail_project, timeline_mulai, timeline_selesai, nama_perusahaan, alamat, nama_pic, notes
- **Features**:
  - Wizard form with auto-fill from Deal
  - Deadline calculation
  - Client info auto-populate

#### Task
- **Fields**: nama_task, project_id, deal_id, detail_task, mulai_task, selesai_task, status, proritas, nama_layanan, creator_id, users (many-to-many)
- **Status**: Log, Process, Review, Done, Hold
- **Priority**: Urgent, Normal, Low
- **Features**:
  - Kanban Board (using Mokhosh/FilamentKanban)
  - Multi-user assignment
  - Notification on assignment

### 3. Finance

#### Expense
- **Fields**: user_id, submission_date, project_id, keterangan_expense, amount, status, attachment, paid, paid_by, reference
- **ItemExpenses**: name, description, price, quantity, subtotal
- **Status**: Pending, Disetujui, Ditolak, Dibayarkan, Selesai
- **Features**:
  - Role-based visibility (Director/Finance see all, others see own)
  - Upload invoice
  - Payment processing with budget deduction
  - Approval workflow

#### Advance
- **Fields**: user_id, submission_date, project_id, keterangan_advance, amount, status, paid, paid_by
- **ItemAdvance**: detail items
- **Features**: Realisasi tracking, approval workflow

#### Budget
- **Fields**: name, description, amount
- **Relations**: BudgetLog (automatic tracking)

#### Invoice (via TomatoPHP)
- **Types**: Sale, Estimate (Proforma)
- **Features**: UUID generation, item management, payment tracking

#### Analytics Dashboard
- **Metrics**:
  - Revenue & Billed
  - Expenses & Profit
  - Gross Margin
  - Burn Rate Weekly
  - Cash Runway Weeks
  - Overdue Invoices
- **Sales Funnel**:
  - Leads Count
  - Conversion Rate
  - Quotations
  - Deals
- **Risk Score** (0-100):
  - Margin < 20% → +20
  - Conversion < 15% → +20
  - Runway < 4 weeks → +30
  - DP Coverage < 20% → +20
  - Overdue invoices → +10

### 4. HR (Human Resources)

#### Employee
- **Fields**: nik, firstname, lastname, email, phone, address, position, npwp, ktp, file_npwp, division
- **Divisions**: Social Media Specialist, Finance, HR, Graphic Design, Video Editor
- **Positions**: Staff, Supervisor, Manager, Director

#### Absence
- **Fields**: user_id, category, description, start_time, end_time, approval_status, approved_by, work_report, sick_letter, rejection_reason
- **Categories**: cuti, wfa, tukar_hari, sakit
- **Status**: pending, approved, rejected
- **Features**:
  - Sick letter upload (for sakit category)
  - Work report for WFA
  - Approval workflow with notifications
  - Role-based visibility

#### Event/Calendar
- **Fields**: title, description, start_time, end_time, color, create_by
- **Features**: Event categories with colors

### 5. Tools & Integrations

#### Social Account
- **Fields**: client_id, platform (instagram/facebook/tiktok), platform_account_id, username, page_name, access_token (encrypted), followers_count, media_count
- **Features**:
  - Auto token encryption
  - Sync status tracking
  - Profile/Posts/Insights sync
  - Instagram OAuth integration

#### Meta Ad Account
- **Fields**: client_id, ad_account_id, account_name, access_token, token_expires_at, is_active
- **Features**: Campaign metrics tracking

#### Connected Account
- **Fields**: user_id, provider, account_id, username, profile_picture, access_token (encrypted)
- **Features**: Per-user social account connection

---

## 🗄️ Database Models (36 Models)

### Core Models

| Model | Table | Key Relationships |
|-------|-------|-------------------|
| **User** | users | hasMany: Task, PurchaseRequest, Expense, Event, Asset, Absence, Advance; belongsToMany: Task |
| **Leads** | leads | belongsToMany: Product; hasOne: Deal |
| **Deal** | deals | belongsToMany: Product; hasMany: Project, Task, Invoice, Quotation, Contract |
| **Product** | products | belongsToMany: Leads, Deal |
| **Project** | projects | belongsTo: Deal; hasMany: Task, ProjectTimeline |
| **Task** | tasks | belongsToMany: User; belongsTo: Project, Deal |
| **PerformanceIndicator** | performance_indicators | belongsTo: Task, User |

### Finance Models

| Model | Table | Relationships |
|-------|-------|---------------|
| **Expense** | expenses | belongsTo: User (submitter, paid_by, approved_by), Project; hasMany: ItemExpense |
| **ItemExpense** | item_expenses | belongsTo: Expense |
| **Advance** | advances | belongsTo: User, Project; hasMany: ItemAdvance |
| **ItemAdvance** | item_advances | belongsTo: Advance |
| **Budget** | budgets | hasMany: BudgetLog |
| **BudgetLog** | budget_logs | belongsTo: Budget, User, Expense, Advance, Invoice |

### CRM Models

| Model | Table | Relationships |
|-------|-------|---------------|
| **Quotation** | quotations | hasMany: QuotationItem |
| **QuotationItem** | quotation_items | belongsTo: Quotation |
| **Contract** | contracts | belongsTo: Deal |
| **PurchaseRequest** | purchase_requests | belongsTo: User |
| **Asset** | assets | belongsTo: User (penanggung_jawab) |

### Social Media Models

| Model | Table | Relationships |
|-------|-------|---------------|
| **SocialAccount** | social_accounts | belongsTo: Deal; hasMany: SocialPost, SocialAccountInsight |
| **SocialPost** | social_posts | belongsTo: SocialAccount; hasOne: SocialPostMetric |
| **SocialAccountInsight** | social_account_insights | belongsTo: SocialAccount |
| **MetaAdAccount** | meta_ad_accounts | belongsTo: Deal; hasMany: MetaCampaignMetric |
| **ConnectedAccount** | connected_accounts | belongsTo: User |

---

## 🔐 Security & Authorization

### Role-Based Access Control (Spatie Permission)
- **Roles**: super_admin, Director, Head, Finance, Staff
- **Permission Groups**: view_any_*, view_*, create_*, update_*, delete_*, restore_*

### Access Patterns

| Resource | Access |
|----------|--------|
| Expense | Director/Finance: see all; Others: own only |
| Absence | Director/Finance/Head: manage; Others: own only |
| Task Kanban | Director/Admin: all tasks; Others: assigned only |
| Analytics | Director/Finance: full access |

### Security Features
- Access token encryption (Crypt facade + 'encrypted' cast)
- Path validation in FileService
- Form state hydration protection
- Page-level shielding (HasPageShield)

---

## 🎨 UI/UX Design

### Navigation Groups

```
CRM          → Leads, Client (Deal), Product, Quotation, Contract
Project      → Project, Task Kanban Board, Project Timeline Board
Finance      → Expense, Advance, Budget, Invoice, Analytics Dashboard
HR           → Employee, Absence
Tools        → Social Account, Connected Account, Meta Ad Account
Access       → User Management
Settings     → General Settings, Mail Settings, PWA Settings
```

### Color Scheme (via ColorHelper)

| Status | Color |
|--------|-------|
| Pending | Warning (Yellow) |
| Approved/Done | Success (Green) |
| Rejected/Cancelled | Danger (Red) |
| In Progress | Info (Blue) |
| Default | Gray |

### Theme Customization
- Primary color configurable via GeneralSettings
- Company branding (logo, name)
- Quotation/Invoice template customization

---

## 📡 API Routes

### Public Routes
```
GET  /quotations/{quotation}              - Show quotation PDF
GET  /quotations/{quotation}/download    - Download quotation PDF
GET  /invoices/{invoice}/download        - Download invoice PDF
GET  /contracts/{contract}/preview       - Preview contract HTML
GET  /contracts/{contract}/download-pdf   - Download contract PDF
GET  /manifest.json                      - PWA manifest
```

### Authenticated Routes
```
POST /send-whatsapp                      - Send WhatsApp message
POST /timeline/update                    - Update project timeline
GET  /auth/instagram                      - Instagram OAuth redirect
GET  /auth/instagram/callback            - Instagram OAuth callback
POST /api/connected-accounts/{id}/sync   - Sync account
POST /api/connected-accounts/{id}/disconnect
POST /api/connected-accounts/{id}/refresh-token
```

### Admin Routes
```
GET  /admin                              - Dashboard
GET  /admin/tasks                        - Task List
GET  /admin/task-kanban-board            - Kanban Board
GET  /admin/project-timeline-board       - Timeline Board
GET  /admin/analytics-dashboard          - Analytics
GET  /admin/settings/general             - General Settings
GET  /admin/settings/mail                - Mail Settings
GET  /admin/pwa-settings                  - PWA Settings
```

---

## 🔧 Services

### ContractTemplateService
- Generate HTML contract from Deal data
- SOW table from products
- Payment terms calculation
- Signature sections

### MetricsEngine
- Financial snapshot (revenue, expenses, profit, margin)
- Sales funnel metrics (leads, conversions)
- Risk score calculation

### InstagramService / FacebookPageService
- Profile sync
- Posts sync
- Insights sync
- Token refresh

### MetaAdsService
- Account sync
- Campaign metrics

### PushNotificationService
- Web push subscriptions
- Send to user/all
- FCM integration

### GeminiService
- AI analysis integration

### WablasService
- WhatsApp messaging

---

## 📝 Business Flow

### Lead to Contract Flow
```
Leads → [Convert] → Deal → [Create Quotation] → Quotation
                                      ↓
                              [Convert to Proforma]
                                      ↓
                                Invoice (Estimate)
                                      ↓
                              [Create Contract] → Contract
```

### Project Flow
```
Deal → [Create Project] → Project
                              ↓
                    [Create Tasks] → Task (Kanban)
                              ↓
                    [Track Timeline] → ProjectTimeline
```

### Finance Flow
```
Expense/Advance → [Submit] → Pending → [Approve] → Disetujui → [Pay] → Dibayarkan → [Complete] → Selesai
                                  ↓
                               [Reject]
```

---

## ⚙️ Settings

### GeneralSettings
- **Brand**: name, logo, favicon, theme colors
- **Event Categories**: color-coded labels
- **Quotation Template**: cover pages, theme color, logo, company info, signature, terms
- **Invoice Template**: theme color, logo, company info, bank info, signature

---

## 🧩 Plugins & Dependencies

| Package | Purpose |
|---------|---------|
| filament/filament | Admin panel |
| filament-shield | Role-based access |
| tomatophp/filament-invoices | Invoice management |
| mokhosh/filament-kanban | Kanban board |
| spatie/laravel-permission | Permission system |
| laravel/sanctum | API auth |
| barryvdh/laravel-dompdf | PDF generation |
| facebook/php-business-sdk | Meta Ads API |
| google-gemini | AI analysis |
| jeffgreco13/filament-breezy | User profile |

---

## 📁 Key Files

| File | Description |
|------|-------------|
| `app/Models/User.php` | User model with roles & media |
| `app/Filament/Resources/DealResource.php` | Deal CRUD with relations |
| `app/Filament/Pages/TaskKanbanBoard.php` | Kanban board page |
| `app/Filament/Pages/AnalyticsDashboard.php` | Financial analytics |
| `app/Services/ContractTemplateService.php` | Contract HTML generation |
| `app/Services/MetricsEngine.php` | Financial metrics calculation |
| `app/Settings/GeneralSettings.php` | Application settings |
| `app/Helpers/ColorHelper.php` | Status color mapping |
| `routes/web.php` | Web routes |
| `config/filament.php` | Filament configuration |

---

## 🚀 Installation

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

# Start development server
php artisan serve
```

---

## 📌 Notes

- Aplikasi ini menggunakan Laravel 11 dengan PHP 8.3+
- Semua access token dienkripsi menggunakan Laravel Crypt
- Observer pattern digunakan untuk Expense, Advance, Absence, Task
- Quotation UUID format: `#DG/YYMM/XXXXX`
- Invoice UUID format: `#PI/YYMMMMDD/XXXXX`
- Role Director/Finance memiliki akses penuh ke data finance
- Task Kanban menggunakan plugin Mokhosh/FilamentKanban

---

**Version**: 1.0
**Author**: ERP Creative Team