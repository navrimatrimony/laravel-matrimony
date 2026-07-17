# Suchak Engine MVP Inventory

Goal 1 inventory for the Laravel Suchak module. Routes and services remain available; MVP config hides low-priority UI.

## Classification legend

| Label | Meaning |
|-------|---------|
| **Core** | MVP daily operations |
| **Optional** | Useful but not primary nav |
| **Future** | Hidden in MVP UI (`config/suchak_mvp.php`) |
| **Duplicate** | Overlapping responsibility (documented, not removed) |

## Services (`app/Modules/Suchak/Services/`)

| Service | Class |
|---------|-------|
| Core | AccessService, RegistrationService, RepresentationService, ConsentService, SourceLinkService, IntakeApplyService |
| Core | CustomerLifecycleService, CustomerListService, PackageCatalogService, AgreementService |
| Core | PaymentRequestService, CustomerPaymentService, PaymentStatusService, PaymentCollectorResolver |
| Core | RequestPipelineService, CrossSearchService, CollaborationService, CandidateMaskingService |
| Core | CrmLedgerService, DailyOpportunityService, WorkflowAutomationService, PolicyService, LimitService |
| Core | AccountLifecycleService, EntitlementService, BillingCatalogService, PlanPaymentService |
| Core | PdfQrFoundationService, QrCodeImageService, ActivityLogger, QualityControlService, SafetyService |
| Optional | VisitConfirmationService, PlatformPayoutService, LeadAllocationService, GrowthRewardService |
| Optional | IncomeAnalyticsService, CustomerPortalService, CustomerPaymentCorrectionService |
| Optional | PublicMarketplaceService, ProfileUpdateSuggestionService |
| Future | TrainingAcademyService, OfflineCampService, ExportRetentionService, RetentionCampaignService |
| Future | WhiteLabelSharingKitService, WorkAreaService, RiskComplianceCenterService |
| Internal | ScheduledJobsConsolidationService, RepresentationShutdownService |

## Shared support

| File | Role |
|------|------|
| `Support/SuchakWorklistSourceQueries.php` | Shared due-follow-up, ledger, payment-request queries for worklist + reminders |
| `app/Support/Suchak/SuchakMvpFeatures.php` | MVP nav/tab/panel visibility |
| `config/suchak_mvp.php` | MVP surface toggles |

## Controllers

### Suchak (`app/Http/Controllers/Suchak/`)

Core: Dashboard, AccountRequest, IntakeSource, ManualProfile, Consent, BiodataExport, CrossSearch, Collaboration, CrmLedger, ProfileRequestReply, AccountSettings

Public/token: QrScan, PaymentRequestPublic, CustomerPortal, PublicConsent, ReceiptVerification, PublicMarketplace

Future (hidden nav): TrainingAcademy, OfflineCamp, ExportRetention

### Admin Suchak (`app/Http/Controllers/Admin/Suchak/`)

Core: Dashboard, AccountVerification, Safety, Settings, PlanCatalog, PayoutController

Future (hidden admin links): Retention, Academy

## Routes

- `routes/web/suchak.php` — operator + public token surfaces
- `routes/web/admin-suchak.php` — admin governance
- `routes/web/member.php` — `PublicProfileRequestController` (member → suchak request)

## Models

88 `Suchak*` models. Hub: `SuchakCustomerContext`. Immutable financial/customer tables throw on delete.

## Views

Operator: `resources/views/suchak/*` (dashboard, intakes, search, collaborations, …)

Admin: `resources/views/admin/suchak/*`

Nav: `resources/views/layouts/partials/suchak-navigation.blade.php`

## Jobs / commands

- `GenerateSuchakWorkflowReminders`
- `RunSuchakRetentionArchiveRules`
- Scheduled via `SuchakScheduledJobsConsolidationService` + `routes/console.php`

## Tests

62 files under `tests/Feature/Suchak/`. Gate: `SuchakProductionReadinessGateTest.php`.

## Duplicate / overlap (retained)

| Area | Notes |
|------|-------|
| DailyOpportunity vs WorkflowAutomation | Same sources; worklist is read UI, reminders persist to DB |
| SuchakPlan vs ServicePackage | Platform subscription vs customer service fees — both kept |
| Pipeline vs CustomerContext | Public requests vs suchak-initiated CRM — both kept |

## MVP hidden surfaces (default)

- Nav: Network, Tools top-level sections
- Nav sub: Offline Camps, Export/Retention, Training Academy
- Dashboard tab: Sharing (white-label kit)
- Dashboard panel: WhatsApp template catalog
- Admin links: Retention, Academy

Direct URLs and APIs remain reachable for tests and power users.
