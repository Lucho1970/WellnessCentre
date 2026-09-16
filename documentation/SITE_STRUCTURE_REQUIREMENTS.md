# Wellness Centre Platform

> Historical additive proposal, consolidated on 16 September 2026 into [Master Requirements](MASTER_REQUIREMENTS.md) and [System Design](SYSTEM_DESIGN.md). Those documents retain existing product scope and resolve role, identity and implementation-status differences. Example domains below are not approved deployment settings.

## Portal and Authentication Restructuring Requirements

**Version:** 1.0  
**Date:** September 2026  
**Author:** Luis Duran

---

# 1. Project Overview

The Wellness Centre website currently serves both public-facing and authenticated users. The platform must be restructured to provide a clear separation between:

1. Public Website Experience
2. Authenticated Portal Experience

The goal is to improve usability, security, maintainability, and scalability while providing a seamless user experience across all user types.

---

# 2. High-Level Architecture

## Public Website

Primary Domain:

```
https://www.wellnesscentre.com
```

Purpose:

- Marketing
- Service information
- Practitioner profiles
- Wellness resources
- Blog/articles
- Contact information
- Appointment booking
- Public user registration
- Public user login

---

## Secure Portal

Subdomain:

```
https://portal.wellnesscentre.com
```

Purpose:

- Daily operational activities
- Appointment management
- Client management
- Practitioner workflows
- Administrative functions

The portal will contain role-based experiences for:

- Client
- Practitioner
- Administrator

---

# 3. Authentication Strategy

## Internal Users

Internal users will authenticate using Microsoft Entra ID.

User Types:

- Practitioner
- Administrator
- Super Administrator

Authentication Method:

- Microsoft Entra ID
- Single Sign-On (SSO)
- Multi-Factor Authentication enforced through Entra ID policies

The existing Entra ID configuration should remain the authoritative identity source for internal users.

---

## Public Users

Public users must NOT require Entra ID accounts.

Supported authentication providers:

- Google
- Microsoft Personal Account
- Facebook (optional)
- Apple ID (recommended)
- Email + Password (optional but recommended)

Authentication flow should allow future providers to be added without significant architectural changes.

Recommended implementation:

- OpenID Connect / OAuth2 based identity provider
- Customer identity platform (Azure AD B2C / Entra External ID or equivalent)

---

# 4. User Types

## Client

External customer of the wellness centre.

Capabilities:

- Register account
- Login
- Manage profile
- View appointments
- Book appointments
- Cancel appointments
- Reschedule appointments
- View practitioners
- Complete intake forms
- View invoices
- View payment history
- Exchange secure messages

---

## Practitioner

Internal authenticated staff member.

Capabilities:

- View daily schedule
- View upcoming appointments
- Manage availability
- View assigned clients
- Create treatment notes
- Review intake forms
- Send messages to clients
- Update appointment status

Authentication Source:

Microsoft Entra ID

---

## Administrator

Internal management and operational staff.

Capabilities:

- Full practitioner management
- Full client management
- Scheduling controls
- Reporting
- Billing oversight
- User management
- System configuration
- Audit log review

Authentication Source:

Microsoft Entra ID

---

# 5. Login Experience

## Public Website Login

Public website navigation includes:

```
Home
Services
Practitioners
Resources
About
Contact
Book Appointment
Login
```

Selecting Login displays:

### Internal Staff Login

```
Sign in with Microsoft Entra ID
```

### Client Login

```
Continue with Google
Continue with Microsoft
Continue with Facebook
Continue with Apple
Email and Password
Create Account
```

---

# 6. Post-Login Routing

After authentication, users should be automatically redirected based on role.

## Client

Redirect to:

```
https://portal.wellnesscentre.com/client
```

Landing Page:

```
Client Dashboard
```

---

## Practitioner

Redirect to:

```
https://portal.wellnesscentre.com/practitioner
```

Landing Page:

```
Today's Schedule
```

---

## Administrator

Redirect to:

```
https://portal.wellnesscentre.com/admin
```

Landing Page:

```
Administrative Dashboard
```

---

# 7. Portal Structure

## Client Portal

Navigation:

```
Dashboard
Appointments
Book Appointment
Practitioners
Messages
Forms
Invoices
Profile
Logout
```

Dashboard widgets:

- Upcoming appointments
- Recent messages
- Outstanding forms
- Account notifications

---

## Practitioner Portal

Navigation:

```
Dashboard
Schedule
Clients
Treatment Notes
Messages
Availability
Reports
Profile
Logout
```

Dashboard widgets:

- Today's appointments
- Upcoming appointments
- New client submissions
- Notifications

---

## Administrator Portal

Navigation:

```
Dashboard
Clients
Practitioners
Appointments
Billing
Reports
Settings
Audit Logs
User Management
Logout
```

Dashboard widgets:

- Daily appointment volume
- Practitioner utilization
- Revenue metrics
- System alerts

---

# 8. Authorization Model

Implement Role-Based Access Control (RBAC).

Roles:

```
Client
Practitioner
Admin
SuperAdmin
```

Permissions must be enforced through API and backend services.

UI visibility alone is not considered security.

---

# 9. Shared Backend Services

The Public Website and Portal should share:

- User database
- Appointment system
- Practitioner database
- Notification services
- Messaging services
- Reporting services

Architecture should avoid duplication of business logic.

---

# 10. Security Requirements

Mandatory requirements:

### Authentication

- Secure token-based authentication
- MFA for internal users
- Session timeout
- Secure password policies

### Authorization

- Role-based authorization
- Resource-level permissions
- API authorization validation

### Data Protection

- Encryption in transit
- Encryption at rest
- Secure handling of personal information
- Audit trails

---

# 11. Audit Requirements

Audit logging must capture:

- Login events
- Failed login attempts
- Appointment changes
- Treatment note changes
- User updates
- Practitioner changes
- Billing modifications
- Administrative actions

Audit logs should be searchable.

---

# 12. User Experience Requirements

Maintain consistent branding across:

- Public website
- Client portal
- Practitioner portal
- Admin portal

Shared elements:

- Logo
- Color palette
- Typography
- Accessibility standards

Operational dashboards should prioritize productivity over marketing content.

---

# 13. Mobile Requirements

Responsive design required for:

- Mobile devices
- Tablets
- Desktop browsers

Client portal should be fully functional on mobile devices.

Practitioner portal should support tablet workflows.

---

# 14. Accessibility Requirements

WCAG 2.1 AA compliance required.

Requirements include:

- Keyboard navigation
- Screen reader compatibility
- Appropriate contrast ratios
- Accessible forms
- Accessible error messaging

---

# 15. Future Expansion Considerations

Architecture should support:

- Multiple clinic locations
- Online payments
- Telehealth/video appointments
- Additional SSO providers
- Mobile application integration
- Client document storage
- Automated reminders
- AI-powered scheduling assistance

---

# 16. Recommended Technical Architecture

```text
www.wellnesscentre.com
│
├── Public Website
│   ├── Marketing Pages
│   ├── Practitioner Profiles
│   ├── Service Listings
│   ├── Booking
│   ├── Registration
│   └── Login
│
└── portal.wellnesscentre.com
    │
    ├── Client Portal
    │
    ├── Practitioner Portal
    │
    └── Admin Portal
            │
            ├── User Management
            ├── Scheduling
            ├── Billing
            ├── Reporting
            └── System Administration
```

---

# 17. Success Criteria

The implementation will be considered complete when:

- Public website and operational portal are logically separated.
- Clients authenticate using consumer identity providers.
- Practitioners and administrators authenticate through Microsoft Entra ID.
- Role-based dashboards are automatically presented after login.
- Security controls are enforced at both UI and API levels.
- Mobile and accessibility requirements are satisfied.
- Future identity providers can be added with minimal code changes.
- Shared backend services support all portal and website functionality.
