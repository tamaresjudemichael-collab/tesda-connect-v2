# TESDA Connect V2 — Panel Revision Plan

## Snapshot findings

- Stack: Laravel 13, Livewire 4, Volt, Flux UI, Fortify; SQLite in the supplied local snapshot.
- Institution model and `institution_user` pivot already exist. `InstitutionScope::idsFor()` gives administrators cross-institution oversight and limits other staff to explicitly assigned institutions.
- Roles in `User::roleLabel()`: administrator, registrar, focal_person, applicant, scholar.
- The existing focal-person role already has distinct course, operational document, and form workspaces, and tests reject non-focal access and unassigned-institution uploads. Keep the role and document its boundaries rather than merging it into administrator.
- The existing public landing page named only Mamburao Integrated Farm in its hero, even though its footer referenced both Mamburao Integrated Farm and Balli School of Technology. The updated landing page presents both as equal institution choices.
- Course records currently store descriptions/requirements/curriculum references, but the snapshot has no dedicated private course-material library. This patch adds one.

## Role responsibility matrix

| Role | Purpose | Allowed responsibilities | Boundaries |
|---|---|---|---|
| Administrator | System governance and oversight | Manage accounts/institutions/courses, monitor activity, backups/health, cross-institution reporting | High-privilege actions must be audited; never use this role for routine learner work |
| Registrar | Enrollment intake and official learner records | Review applications and requirements, verify/confirm eligible enrollment, manage intake and forward batches, access permitted reports | Institution-scoped; no system-wide account/security or backup administration |
| Focal Person | Institution training operations | Manage assigned courses, competency/progress/attendance, certificates, operational documents/forms, course materials, relevant reports | Assigned institutions only; no system account administration or unrestricted learner document access |
| Applicant | Prospective learner | Submit/update own registration and requirements, track own application and announcements | Cannot see other applicants' records or private course materials until a confirmed course enrollment exists |
| Scholar | Enrolled learner | Access own learner portal, attendance/progress/status, announcements, and published resources for own confirmed course | Read-only access to own records and relevant learning materials; cannot manage course content or other learners |

## Implemented in this patch

1. Landing page distinguishes Mamburao Integrated Farm (MIF) and Balli School of Technology (BST) with separate cards and sign-in entry points.
2. Adds a course-material library for administrator/focal person upload, publish/hide, and delete actions. Uploads are private (`local` disk), limited to common learning file formats and 20 MB, and logged.
3. Adds learner-facing materials page. It shows only published resources attached to the learner's latest confirmed application/course.
4. Adds a download controller that checks active account, staff institution scope or matching confirmed learner enrollment before downloading. It returns private/no-store headers and `nosniff`.
5. Adds a database migration with an index for course material visibility/retrieval.
6. Adds feature tests for institution scope, learner access, unpublished files, and role restrictions.

## Important caveats / next steps

- This is an overlay patch based on the uploaded snapshot, not a modification of the local XAMPP project.
- Sidebar links are included in the patch so both staff and learners can find the new pages.
- Run the targeted tests and review any failures before deployment. Do not run `migrate:fresh` on the real database.
- Offline access for course materials is not implemented by this first increment. A later phase should define explicit caching, expiry, file-size limits, and synchronization rules. Do not describe this new library as offline-ready yet.
- Production cost estimates depend on the client's internet, server, backup destination, support arrangements, and expected file volume. The client should receive a restore runbook and a tested backup schedule before handover.
