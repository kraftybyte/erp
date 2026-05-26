# ARCHITECTURE DIAGRAMS - ERP Creative

> Visual diagrams to help AI understand the system architecture

---

## 1. System Architecture

```mermaid
graph TB
    subgraph Frontend
        A[Filament Admin Panel]
        B[TailwindCSS]
        C[Livewire]
    end

    subgraph Backend
        D[Laravel 11]
        E[PHP 8.3+]
    end

    subgraph Data
        F[(MySQL)]
        G[(File Storage)]
    end

    subgraph External
        H[Meta API<br/>Instagram/Facebook]
        I[WhatsApp Wablas]
        J[Gemini AI]
    end

    A --> C
    C --> D
    D --> E
    D --> F
    D --> G
    D --> H
    D --> I
    D --> J
```

---

## 2. Data Flow - Lead to Contract

```mermaid
flowchart LR
    A[Leads] -->|Convert| B[Deal]
    B -->|Create| C[Quotation]
    C -->|Convert| D[Invoice<br/>Proforma]
    D -->|Create| E[Contract]

    B -->|Create| F[Project]
    F -->|Create| G[Task]
    G -->|Kanban| H[TaskBoard]
```

---

## 3. Finance Flow

```mermaid
stateDiagram-v2
    [*] --> Pending
    Pending --> Disetujui: Approve
    Pending --> Ditolak: Reject
    Disetujui --> Dibayarkan: Bayarkan
    Ditolak --> [*]
    Dibayarkan --> Selesai: Selesaikan
    Selesai --> [*]
```

---

## 4. Entity Relationships

```mermaid
erDiagram
    USER {
        int id PK
        string firstname
        string lastname
        string email
        string position
        string division
    }

    LEADS {
        int id PK
        string nama_pic
        string nama_perusahaan
        string email
        string status
        bool is_converted
    }

    DEAL {
        int id PK
        int lead_id FK
        string nama_perusahaan
        string status
    }

    PRODUCT {
        int id PK
        string nama_layanan
        decimal harga_layanan
    }

    PROJECT {
        int id PK
        int deal_id FK
        string nama_project
        date timeline_mulai
        date timeline_selesai
    }

    TASK {
        int id PK
        int project_id FK
        string nama_task
        string status
        string proritas
    }

    QUOTATION {
        int id PK
        string uuid
        string nama_perusahaan
        decimal grand_total
        string status
    }

    CONTRACT {
        int id PK
        int deal_id FK
        string type
        decimal total_value
        date effective_date
        date expiration_date
    }

    EXPENSE {
        int id PK
        int user_id FK
        int project_id FK
        decimal amount
        string status
    }

    ABSENCE {
        int id PK
        int user_id FK
        string category
        string approval_status
    }

    USER ||--o{ TASK : assigned
    USER ||--o{ EXPENSE : submits
    USER ||--o{ ABSENCE : requests
    LEADS ||--o| DEAL : converts
    DEAL ||--o{ PROJECT : has
    DEAL ||--o{ PRODUCT : offers
    DEAL ||--o{ QUOTATION : receives
    DEAL ||--o{ CONTRACT : signs
    PROJECT ||--o{ TASK : contains
    QUOTATION ||--o| CONTRACT : generates
```

---

## 5. Navigation Structure

```mermaid
graph TD
    subgraph CRM
        A1[Leads]
        A2[Client]
        A3[Product]
        A4[Quotation]
        A5[Contract]
    end

    subgraph Project
        B1[Project]
        B2[Task Kanban]
        B3[Timeline Board]
    end

    subgraph Finance
        C1[Expense]
        C2[Advance]
        C3[Budget]
        C4[Invoice]
        C5[Analytics]
    end

    subgraph HR
        D1[Employee]
        D2[Absence]
    end

    subgraph Tools
        E1[Social Account]
        E2[Connected Account]
        E3[Meta Ad Account]
    end

    subgraph Access
        F1[User Management]
    end

    subgraph Settings
        G1[General]
        G2[Mail]
        G3[PWA]
    end
```

---

## 6. User Permissions Matrix

```mermaid
graph LR
    subgraph Director
        DA[All Data]
        DAP[All Permissions]
    end

    subgraph Finance
        FA[All Finance Data]
        FAP[Finance Permissions]
    end

    subgraph Head
        HA[Team Tasks]
        HAP[HR Permissions]
    end

    subgraph Staff
        SA[Own Data Only]
        SAP[Basic Permissions]
    end
```

---

## 7. Task Workflow

```mermaid
flowchart TD
    subgraph Kanban Board
        T1[Log]
        T2[Process]
        T3[Review]
        T4[Done]
        T5[Hold]
    end

    T1 -->|Start| T2
    T2 -->|Complete| T3
    T3 -->|Approve| T4
    T3 -->|Reject| T2
    T4 -->|Reopen| T2
    T1 -->|Hold| T5
    T5 -->|Resume| T1
```

---

## 8. API Flow

```mermaid
sequenceDiagram
    participant User
    participant Filament
    participant Controller
    participant Service
    participant Database

    User->>Filament: Request
    Filament->>Controller: Route
    Controller->>Service: Call Method
    Service->>Database: Query
    Database-->>Service: Result
    Service-->>Controller: Response
    Controller-->>Filament: View
    Filament-->>User: Display
```

---

## 9. Key Services Architecture

```mermaid
graph TD
    subgraph Services
        S1[ContractTemplateService]
        S2[MetricsEngine]
        S3[InstagramService]
        S4[FacebookPageService]
        S5[MetaAdsService]
        S6[PushNotificationService]
        S7[GeminiService]
        S8[WablasService]
    end

    subgraph Usage
        R1[ContractResource]
        R2[AnalyticsDashboard]
        R3[SocialAccountResource]
        R4[PwaSettings]
    end

    R1 --> S1
    R2 --> S2
    R3 --> S3
    R3 --> S4
    R3 --> S5
    R2 --> S6
    R2 --> S7
    R2 --> S8
```

---

## 10. Database Schema Overview

```mermaid
graph BT
    subgraph Core Tables
        M1[users]
        M2[leads]
        M3[deals]
        M4[products]
    end

    subgraph Project Tables
        M5[projects]
        M6[tasks]
        M7[project_timelines]
    end

    subgraph Finance Tables
        M8[expenses]
        M9[advances]
        M10[budgets]
        M11[invoices]
    end

    subgraph Social Tables
        M12[social_accounts]
        M13[meta_ad_accounts]
    end

    M2 --> M3
    M3 --> M5
    M5 --> M6
    M3 --> M8
    M3 --> M9
    M3 --> M12
```

---

## 11. Settings Schema

```mermaid
graph TD
    subgraph GeneralSettings
        G1[Brand]
        G1A[name]
        G1B[logo]
        G1C[favicon]
        G1D[theme]

        G2[Event Categories]
        G2A[color]
        G2B[label]

        G3[Quotation Template]
        G3A[theme_color]
        G3B[company_info]
        G3C[signature]
        G3D[terms]

        G4[Invoice Template]
        G4A[theme_color]
        G4B[company_info]
        G4C[bank_info]
        G4D[signature]
    end
```

---

**End of Architecture Diagrams**