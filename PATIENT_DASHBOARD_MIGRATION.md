# OpenEMR Patient Dashboard Migration

## Scope

This migration is intentionally **frontend-only**.

What changed:
- Added a new React + TypeScript + Vite application under `frontend/patient-dashboard/`
- Implemented a read-only patient dashboard against the existing OpenEMR FHIR and OAuth endpoints
- Added TanStack Query for API-backed loading, caching, loading states, and error handling

What did **not** change:
- No PHP backend replacement
- No OpenEMR database schema changes
- No REST controller changes
- No FHIR controller changes
- No OAuth server changes
- No portal backend changes
- No write actions or new clinical workflows

## Framework Defense

### Why React + TypeScript + Vite

This migration targets the patient dashboard presentation layer, which benefits from:
- componentized UI composition
- explicit typed contracts between API responses and dashboard rendering
- a modern local-dev workflow with fast rebuilds
- isolated frontend iteration without destabilizing the OpenEMR backend

React is a strong fit because the current dashboard is naturally a persistent header plus repeatable clinical cards.

TypeScript adds value because:
- FHIR resources are nested and shape-sensitive
- card-level rendering logic is safer when mapped into typed dashboard models
- future extension work is easier when the data contracts are explicit

Vite is a good fit because:
- fast startup and rebuild time
- simple standalone frontend workspace
- straightforward local proxying to OpenEMR for `/apis`, `/oauth2`, and `/interface`

### Why TanStack Query

TanStack Query was chosen because the dashboard is read-heavy and API-driven.

It improves:
- caching of repeated card fetches
- request de-duplication
- loading and error state management per card
- predictable refetch behavior for patient-context changes

This is a better fit than hand-managed `useEffect` fetch chains because each dashboard card behaves like a separate data resource with its own empty, loading, and error state.

## Existing Backend Reused

The frontend app consumes the existing OpenEMR FHIR surface, including:
- `Patient`
- `AllergyIntolerance`
- `Condition`
- `MedicationRequest`
- `Medication`
- `CareTeam`
- `Encounter`

It also includes a best-effort SMART/OAuth helper that uses existing OpenEMR discovery and authorization endpoints:
- `GET /apis/{site}/fhir/.well-known/smart-configuration`
- `GET /oauth2/{site}/.well-known/openid-configuration`
- `POST /oauth2/{site}/token`
- `GET /oauth2/{site}/authorize`

## Feature Coverage

Implemented dashboard sections:
- Persistent patient header
  - name
  - date of birth
  - sex
  - MRN
  - active status
- Allergies card from `AllergyIntolerance`
- Problem List card from `Condition`
- Medications card from `MedicationRequest` and `Medication`
- Prescriptions card from `MedicationRequest`
- Care Team card from `CareTeam`
- Encounter History card from `Encounter`

All cards include:
- loading state
- empty state
- error state
- optional demo fallback state when the API is unavailable

## Demo Fallback Policy

Fallback data is only used when the API is unavailable, such as:
- missing FHIR base URL
- network failure
- server-side unavailability

Fallback data is clearly marked as:
- `Demo fallback`
- `Demo fallback — OpenEMR API unavailable. Backend data remains read-only and untouched.`

This keeps the migration demo-safe without pretending fallback data is live EHR data.

## Tradeoffs

### Gains
- Modern typed frontend boundary for patient dashboard work
- Better maintainability for dashboard cards
- Easier testability and future extension
- Cleaner async data model with TanStack Query
- Backend preserved as the system of record

### Tradeoffs
- Adds a second frontend stack alongside existing PHP-rendered views
- OAuth launch wiring still depends on site-specific SMART client registration
- Exact visual parity is approximated rather than pixel-for-pixel duplicated
- Some OpenEMR deployments may still prefer same-origin cookie auth or iframe launch over standalone OAuth

## Local Run

From the repo root:

```bash
cd frontend/patient-dashboard
npm install
npm run dev
```

Optional environment variables:
- `VITE_OPENEMR_BASE_URL`
- `VITE_OPENEMR_SITE`
- `VITE_OPENEMR_FHIR_BASE_URL`
- `VITE_OPENEMR_CLIENT_ID`
- `VITE_OPENEMR_REDIRECT_URI`
- `VITE_OPENEMR_SCOPE`
- `VITE_OPENEMR_DEFAULT_PATIENT_ID`
- `VITE_OPENEMR_DEMO_FALLBACK`
- `OPENEMR_PROXY_TARGET`

Example local query string:

```text
http://localhost:5174/?patient=123
```

## Backend Integrity Statement

For this migration, the OpenEMR backend remains untouched and continues to provide:
- authentication
- authorization
- FHIR resource access
- REST/FHIR routing
- database access
- patient workflow rules

This work is a presentation-layer migration only, not an OpenEMR platform rewrite.
