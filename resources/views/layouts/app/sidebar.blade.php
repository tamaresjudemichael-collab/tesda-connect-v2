
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')

        <style>
            .tesda-sidebar {
                /* Wider desktop rail, but never wider than the available viewport. */
                --sidebar-width: 19rem;
                --sidebar-width-collapsed: 4.25rem;
                width: min(19rem, calc(100vw - 1rem)) !important;
                max-width: calc(100vw - 1rem) !important;
                min-width: 0;
                box-sizing: border-box;
                background-color: #102f68 !important;
                color: #ffffff !important;
                overflow-x: hidden !important;
            }

            .tesda-sidebar,
            .tesda-sidebar * {
                box-sizing: border-box;
            }

            /* Keep the vertical menu scrollable while eliminating the bottom horizontal bar. */
            .tesda-sidebar [data-flux-sidebar-nav],
            .tesda-sidebar [data-flux-sidebar-group] {
                min-width: 0 !important;
                max-width: 100% !important;
                overflow-x: hidden !important;
            }

            /* Only the main navigation should scroll; expandable groups must not create nested scrollbars. */
            .tesda-sidebar > [data-flux-sidebar-nav] {
                scrollbar-width: thin;
                scrollbar-color: rgba(191, 219, 254, 0.55) transparent;
                overscroll-behavior: contain;
            }

            /* Use the same typography for every role (administrator, registrar, focal person, learner). */
            .tesda-sidebar [data-flux-sidebar-item] {
                width: 100%;
                min-width: 0 !important;
                max-width: 100% !important;
                white-space: normal !important;
                font-size: 1rem !important;
                line-height: 1.35 !important;
                font-weight: 600;
            }

            .tesda-sidebar [data-flux-sidebar-item] > span:last-child {
                font-size: inherit !important;
            }

            /* Expandable and non-expandable group labels should have identical sizing. */
            .tesda-sidebar [data-flux-sidebar-group-heading],
            .tesda-sidebar [data-flux-sidebar-group] button[aria-expanded],
            .tesda-sidebar [data-flux-sidebar-group] [role="button"][aria-expanded] {
                font-size: 0.875rem !important;
                line-height: 1.3 !important;
                font-weight: 700 !important;
            }

            .tesda-sidebar [data-flux-sidebar-item] > span:last-child {
                flex: 1 1 auto;
                min-width: 0;
                max-width: 100%;
                white-space: normal !important;
                overflow-wrap: anywhere;
                line-height: 1.35;
            }

            .tesda-sidebar [data-flux-sidebar-item] > svg,
            .tesda-sidebar [data-flux-sidebar-item] > span:first-child:has(svg) {
                flex-shrink: 0;
            }

            .tesda-sidebar [data-flux-sidebar-group-heading] {
                max-width: 100%;
                white-space: normal !important;
                overflow-wrap: anywhere;
                line-height: 1.3;
            }

            .tesda-sidebar .tesda-disabled-nav {
                width: 100%;
                min-width: 0;
                max-width: 100%;
                flex-wrap: wrap;
                overflow-wrap: anywhere;
                font-size: 1rem !important;
                line-height: 1.35 !important;
                font-weight: 600;
            }

            .tesda-sidebar .tesda-disabled-nav > span:first-of-type {
                flex: 1 1 7rem;
                min-width: 0;
                overflow-wrap: anywhere;
                line-height: 1.35;
            }

            .tesda-sidebar .tesda-unavailable-label {
                flex: 0 1 auto;
                max-width: 100%;
                white-space: normal !important;
                overflow-wrap: anywhere;
                margin-left: auto;
            }

            @media (max-width: 640px) {
                .tesda-sidebar {
                    --sidebar-width: 18rem;
                    width: min(18rem, calc(100vw - 0.75rem)) !important;
                    max-width: calc(100vw - 0.75rem) !important;
                }

                .tesda-sidebar [data-flux-sidebar-nav] {
                    padding-inline: 0.5rem;
                }

                /* Do not shrink group labels on mobile; keep typography consistent across roles. */
                .tesda-sidebar [data-flux-sidebar-group-heading],
                .tesda-sidebar [data-flux-sidebar-group] button[aria-expanded],
                .tesda-sidebar [data-flux-sidebar-group] [role="button"][aria-expanded] {
                    font-size: 0.875rem !important;
                }

                .tesda-sidebar .tesda-disabled-nav {
                    gap: 0.5rem;
                    padding-inline: 0.625rem;
                }

                .tesda-sidebar .tesda-unavailable-label {
                    font-size: 0.625rem;
                }
            }

            .tesda-sidebar [data-flux-sidebar-item],
            .tesda-sidebar [data-flux-sidebar-item] > *,
            .tesda-sidebar [data-flux-sidebar-item] svg {
                color: #eaf2ff !important;
            }

            .tesda-sidebar [data-flux-sidebar-item]:hover {
                background-color: #ffffff !important;
                color: #102f68 !important;
            }

            .tesda-sidebar [data-flux-sidebar-item]:hover,
            .tesda-sidebar [data-flux-sidebar-item]:hover *,
            .tesda-sidebar [data-flux-sidebar-item]:hover svg {
                color: #102f68 !important;
            }

            .tesda-sidebar [data-flux-sidebar-item]:focus-visible {
                outline: 2px solid #ffffff !important;
                outline-offset: 2px;
            }

            .tesda-sidebar [data-flux-sidebar-item][data-current],
            .tesda-sidebar [data-flux-sidebar-item][aria-current="page"] {
                background-color: #ffffff !important;
                color: #102f68 !important;
                font-weight: 700 !important;
            }

            .tesda-sidebar [data-flux-sidebar-item][data-current] *,
            .tesda-sidebar [data-flux-sidebar-item][data-current] svg,
            .tesda-sidebar [data-flux-sidebar-item][aria-current="page"] *,
            .tesda-sidebar [data-flux-sidebar-item][aria-current="page"] svg {
                color: #102f68 !important;
            }

            .tesda-sidebar [data-flux-sidebar-group-heading] {
                color: #bfdbfe !important;
            }

            .tesda-sidebar .tesda-disabled-nav {
                color: rgba(234, 242, 255, 0.7) !important;
                cursor: not-allowed;
            }

            .tesda-sidebar .tesda-disabled-nav svg {
                color: rgba(191, 219, 254, 0.65) !important;
            }

            .tesda-sidebar .tesda-unavailable-label {
                color: #bfdbfe !important;
                font-size: 9px;
                line-height: 1.2;
                letter-spacing: 0.02em;
                white-space: nowrap;
            }

            .tesda-sidebar .tesda-nav-subheading {
                color: #bfdbfe !important;
                letter-spacing: 0.1em;
                text-transform: uppercase;
            }

            .tesda-sidebar a,
            .tesda-sidebar a span {
                color: inherit;
            }

            .tesda-sidebar .tesda-brand-name {
                color: #ffffff !important;
            }

            .tesda-sidebar .tesda-brand-accent {
                color: #f6a915 !important;
            }

            .tesda-sidebar .tesda-brand-subtitle {
                color: #bfdbfe !important;
            }

            .tesda-sidebar .tesda-user-name {
                color: #ffffff !important;
            }

            .tesda-sidebar .tesda-user-role {
                color: #bfdbfe !important;
            }

            .tesda-sidebar .tesda-logout {
                color: #ffffff !important;
            }

            .tesda-sidebar .tesda-logout:hover {
                background-color: rgba(255, 255, 255, 0.14) !important;
                color: #ffffff !important;
            }

            .tesda-sidebar .tesda-logout svg {
                color: inherit !important;
            }
        </style>
    </head>

    <body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
        @php($user = auth()->user())

        <flux:sidebar
            sticky
            collapsible="mobile"
            class="tesda-sidebar border-e-0 shadow-xl shadow-slate-900/10"
        >
            <flux:sidebar.header class="border-b border-white/10 px-5 py-5">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3" wire:navigate>
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-white text-sm font-black text-[#102f68]">
                        TC
                    </span>

                    <span class="grid gap-0.5 leading-tight">
                        <span class="tesda-brand-name font-bold tracking-tight">
                            TESDA<span class="tesda-brand-accent">Connect</span>
                        </span>

                        <span class="tesda-brand-subtitle text-[11px]">
                            Training Operations
                        </span>
                    </span>
                </a>

                <flux:sidebar.collapse class="text-white lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav class="min-w-0 max-w-full overflow-x-hidden overflow-y-auto px-3 py-4" style="max-height: calc(100dvh - 13rem);">
                @if ($user->hasRole('administrator'))
                    <flux:sidebar.group heading="DASHBOARD" expandable :expanded="false" data-tesda-sidebar-group="dashboard" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="home"
                            :href="route('dashboard') . '#system-overview'"
                            :current="request()->routeIs('dashboard')"
                            wire:navigate
                        >
                            System Overview
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="chart-bar"
                            :href="route('dashboard') . '#bst-mif-comparison'"
                            :current="false"
                            wire:navigate
                        >
                            BST ↔ MIF Comparison
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="clipboard-document-list"
                            :href="route('system.activity')"
                            :current="request()->routeIs('system.activity')"
                            wire:navigate
                        >
                            Activity Logs
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="chart-bar"
                            :href="route('dashboard') . '#quick-stats'"
                            :current="false"
                            wire:navigate
                        >
                            Quick Stats
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="ENROLLMENT MONITORING" expandable :expanded="false" data-tesda-sidebar-group="enrollment" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="clipboard-document-list"
                            :href="route('enrollments.index', ['status' => 'submitted'])"
                            :current="request()->routeIs('enrollments.index') && request()->query('status') === 'submitted'"
                            wire:navigate
                        >
                            Submissions
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="clipboard-document-list"
                            :href="route('enrollments.index', ['status' => 'confirmed'])"
                            :current="request()->routeIs('enrollments.index') && request()->query('status') === 'confirmed'"
                            wire:navigate
                        >
                            Verified Records
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="document-arrow-down"
                            :href="route('system.forwarded-batches')"
                            :current="request()->routeIs('system.forwarded-batches')"
                            wire:navigate
                        >
                            Forwarded Batches
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="chart-bar"
                            :href="route('system.intake-summary')"
                            :current="request()->routeIs('system.intake-summary')"
                            wire:navigate
                        >
                            Intake Summary
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="SCHEDULING MANAGEMENT" expandable :expanded="false" data-tesda-sidebar-group="scheduling" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="calendar-days"
                            :href="route('schedules.index')"
                            :current="request()->routeIs('schedules.*')"
                            wire:navigate
                        >
                            Create / Edit
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="calendar-days"
                            :href="route('system.calendar')"
                            :current="request()->routeIs('system.calendar')"
                            wire:navigate
                        >
                            Calendar
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="calendar-days"
                            :href="route('system.duration-compliance')"
                            :current="request()->routeIs('system.duration-compliance')"
                            wire:navigate
                        >
                            Duration Compliance
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="calendar-days"
                            :href="route('system.batches')"
                            :current="request()->routeIs('system.batches')"
                            wire:navigate
                        >
                            Batch Management
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="book-open"
                            :href="route('system.courses')"
                            :current="request()->routeIs('system.courses')"
                            wire:navigate
                        >
                            Course Setup
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="building-office-2"
                            :href="route('system.institutions')"
                            :current="request()->routeIs('system.institutions')"
                            wire:navigate
                        >
                            Institution Setup
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="clipboard-document-list"
                            :href="route('competencies.index')"
                            :current="request()->routeIs('competencies.*')"
                            wire:navigate
                        >
                            Competency Setup
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="clipboard-document-list"
                            :href="route('attendance.index')"
                            :current="request()->routeIs('attendance.*')"
                            wire:navigate
                        >
                            Attendance Management
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="SYSTEM MONITORING" expandable :expanded="false" data-tesda-sidebar-group="system-monitoring" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="users"
                            :href="route('system.accounts')"
                            :current="request()->routeIs('system.accounts')"
                            wire:navigate
                        >
                            Accounts
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="shield-check"
                            :href="route('system.activity')"
                            :current="request()->routeIs('system.activity')"
                            wire:navigate
                        >
                            Audit Logs
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="clipboard-document-list"
                            :href="route('announcements.index')"
                            :current="request()->routeIs('announcements.*')"
                            wire:navigate
                        >
                            Announcement Monitor
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="cog"
                            :href="route('system.health')"
                            :current="request()->routeIs('system.health')"
                            wire:navigate
                        >
                            Health
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="REPORTS & OVERSIGHT" expandable :expanded="false" data-tesda-sidebar-group="reports" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="chart-bar"
                            :href="route('progress.competency')"
                            :current="request()->routeIs('progress.competency')"
                            wire:navigate
                        >
                            Progress
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="academic-cap"
                            :href="route('progress.index')"
                            :current="request()->routeIs('progress.index')"
                            wire:navigate
                        >
                            Scholar Monitoring
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="academic-cap"
                            :href="route('certification.index')"
                            :current="request()->routeIs('certification.*')"
                            wire:navigate
                        >
                            Completion
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="chart-bar"
                            :href="route('reports.index')"
                            :current="request()->routeIs('reports.index')"
                            wire:navigate
                        >
                            Performance
                        </flux:sidebar.item>
                        <div class="tesda-nav-subheading px-3 pt-3 pb-1 text-[10px] font-bold">Export</div>
                        <flux:sidebar.item
                            icon="document-arrow-down"
                            :href="route('reports.export', ['type' => 'enrollments'])"
                            :current="false"
                        >
                            Enrollment CSV
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="document-arrow-down"
                            :href="route('reports.export', ['type' => 'competency-progress'])"
                            :current="false"
                        >
                            Competency CSV
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="document-arrow-down"
                            :href="route('reports.export', ['type' => 'attendance'])"
                            :current="false"
                        >
                            Attendance CSV
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @elseif ($user->hasRole('registrar'))
                    <flux:sidebar.group heading="DASHBOARD" expandable :expanded="false" data-tesda-sidebar-group="registrar-dashboard" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="home"
                            :href="route('dashboard')"
                            :current="request()->routeIs('dashboard')"
                            wire:navigate
                        >
                            Dashboard &amp; Recent Submissions
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="users"
                            :href="route('registrar.account-requests')"
                            :current="request()->routeIs('registrar.account-requests')"
                            wire:navigate
                        >
                            Account Requests
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="ENROLLMENT MANAGEMENT" expandable :expanded="false" data-tesda-sidebar-group="registrar-enrollment" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="clipboard-document-list"
                            :href="route('enrollments.index')"
                            :current="request()->routeIs('enrollments.index')"
                            wire:navigate
                        >
                            Review / Verify / Confirm Enrollments
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="calendar-days"
                            :href="route('registrar.intake')"
                            :current="request()->routeIs('registrar.intake')"
                            wire:navigate
                        >
                            Intake Acceptance &amp; Quotas
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="document-arrow-down"
                            :href="route('registrar.forward-batches')"
                            :current="request()->routeIs('registrar.forward-batches')"
                            wire:navigate
                        >
                            Forward Confirmed Batch to Admin
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="STUDENT RECORDS" expandable :expanded="false" data-tesda-sidebar-group="registrar-student-records" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="users"
                            :href="route('registrar.student-records')"
                            :current="request()->routeIs('registrar.student-records')"
                            wire:navigate
                        >
                            Student Records (Search, Update &amp; Archive)
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="ANNOUNCEMENTS" expandable :expanded="false" data-tesda-sidebar-group="registrar-announcements" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="megaphone"
                            :href="route('announcements.index')"
                            :current="request()->routeIs('announcements.index')"
                            wire:navigate
                        >
                            Manage Announcements
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="ACCOUNT MANAGEMENT" expandable :expanded="false" data-tesda-sidebar-group="registrar-account-management" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="users"
                            :href="route('registrar.accounts')"
                            :current="request()->routeIs('registrar.accounts')"
                            wire:navigate
                        >
                            Staff Accounts (Passwords, Verification &amp; Status)
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="INSTITUTIONS" expandable :expanded="false" data-tesda-sidebar-group="registrar-institutions" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="building-office-2"
                            :href="route('registrar.institutions')"
                            :current="request()->routeIs('registrar.institutions')"
                            wire:navigate
                        >
                            Institution Records &amp; Side-by-Side Summary
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @elseif ($user->hasRole('focal_person'))
                    <flux:sidebar.group heading="DASHBOARD" expandable :expanded="false" data-tesda-sidebar-group="focal-dashboard" class="grid text-blue-200">
                        <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            Dashboard &amp; Institution Overview
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="COMPETENCY PROGRESS &amp; ACHIEVEMENT" expandable :expanded="false" data-tesda-sidebar-group="focal-competency" class="grid text-blue-200">
                        <flux:sidebar.item icon="chart-bar" :href="route('progress.competency')" :current="request()->routeIs('progress.competency')" wire:navigate>
                            Progress Charts, Update, Review &amp; Submit Summary
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('competencies.index')" :current="request()->routeIs('competencies.*')" wire:navigate>
                            Competency Units &amp; Tasks Setup
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="chart-bar" :href="route('progress.index')" :current="request()->routeIs('progress.index')" wire:navigate>
                            Scholar Completion Overview
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="COURSE MANAGEMENT" expandable :expanded="false" data-tesda-sidebar-group="focal-courses" class="grid text-blue-200">
                        <flux:sidebar.item icon="book-open" :href="route('focal.courses')" :current="request()->routeIs('focal.courses')" wire:navigate>
                            Register, Update &amp; Archive Courses
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="folder-open" :href="route('learning.materials')" :current="request()->routeIs('learning.materials')" wire:navigate>
                            Course Learning Materials
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="CERTIFICATION MANAGEMENT" expandable :expanded="false" data-tesda-sidebar-group="focal-certification" class="grid text-blue-200">
                        <flux:sidebar.item icon="academic-cap" :href="route('certification.index')" :current="request()->routeIs('certification.*')" wire:navigate>
                            Batch Certification &amp; Issue Certificate
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="DOCUMENT &amp; ADMIN TASKS" expandable :expanded="false" data-tesda-sidebar-group="focal-documents" class="grid text-blue-200">
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('attendance.index')" :current="request()->routeIs('attendance.*')" wire:navigate>
                            Attendance Management
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="folder-open" :href="route('focal.documents')" :current="request()->routeIs('focal.documents')" wire:navigate>
                            Upload / Download Document Library
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="printer" :href="route('focal.forms')" :current="request()->routeIs('focal.forms*')" wire:navigate>
                            Generate Forms &amp; Secretarial Log
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="calendar-days" :href="route('schedules.index')" :current="request()->routeIs('schedules.*')" wire:navigate>
                            Batch Scheduling
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="REPORTS &amp; COMMUNICATION" expandable :expanded="false" data-tesda-sidebar-group="focal-reports" class="grid text-blue-200">
                        <flux:sidebar.item icon="chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.index')" wire:navigate>
                            Reports &amp; Oversight
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="megaphone" :href="route('announcements.index')" :current="request()->routeIs('announcements.index')" wire:navigate>
                            Announcements
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @elseif ($user->hasRole('applicant', 'scholar'))
                    <flux:sidebar.group heading="DASHBOARD" expandable :expanded="false" data-tesda-sidebar-group="learner-dashboard" class="grid text-blue-200">
                        <flux:sidebar.item icon="home" :href="route('learner.portal')" :current="request()->routeIs('learner.portal')" wire:navigate>
                            Student / Scholar Dashboard
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="book-open" :href="route('learning.my-materials')" :current="request()->routeIs('learning.my-materials')" wire:navigate>
                            My Course Materials
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="ENROLLMENT SUBMISSION" expandable :expanded="false" data-tesda-sidebar-group="learner-enrollment" class="grid text-blue-200">
                        <flux:sidebar.item icon="document-text" :href="route('learner.requirements')" :current="request()->routeIs('learner.requirements')" wire:navigate>
                            View Requirements
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('learner.registration')" :current="request()->routeIs('learner.registration')" wire:navigate>
                            Submit Enrollment / Registration Form
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @elseif ($user->hasRole('focal_person'))
                    <flux:sidebar.group heading="MAIN MENU" class="grid text-blue-200">
                        <flux:sidebar.item
                            icon="home"
                            :href="route('dashboard')"
                            :current="request()->routeIs('dashboard')"
                            wire:navigate
                        >
                            Dashboard
                        </flux:sidebar.item>
                    <flux:sidebar.item
                        icon="book-open"
                        :href="route('user-guide')"
                        :current="request()->routeIs('user-guide')"
                        wire:navigate
                    >
                        User Guide
                    </flux:sidebar.item>

                        @if ($user->hasRole('registrar'))
                            <flux:sidebar.item
                                icon="clipboard-document-list"
                                :href="route('enrollments.index')"
                                :current="request()->routeIs('enrollments.*')"
                                wire:navigate
                            >
                                Enrollment Monitoring
                            </flux:sidebar.item>
                        @endif

                        @if ($user->hasRole('registrar', 'focal_person'))
                            <flux:sidebar.item
                                icon="calendar-days"
                                :href="route('schedules.index')"
                                :current="request()->routeIs('schedules.*')"
                                wire:navigate
                            >
                                Scheduling Management
                            </flux:sidebar.item>
                        @endif

                        @if ($user->hasRole('focal_person'))
                            <flux:sidebar.item
                                icon="academic-cap"
                                :href="route('progress.index')"
                                :current="request()->routeIs('progress.*')"
                                wire:navigate
                            >
                                Progress & Achievement
                            </flux:sidebar.item>

                            <flux:sidebar.item
                                icon="clipboard-document-list"
                                :href="route('attendance.index')"
                                :current="request()->routeIs('attendance.*')"
                                wire:navigate
                            >
                                Attendance Management
                            </flux:sidebar.item>

                            <flux:sidebar.item
                                icon="chart-bar"
                                :href="route('progress.competency')"
                                :current="request()->routeIs('progress.competency')"
                                wire:navigate
                            >
                                Competency Progress
                            </flux:sidebar.item>

                            <flux:sidebar.item
                                icon="clipboard-document-list"
                                :href="route('competencies.index')"
                                :current="request()->routeIs('competencies.*')"
                                wire:navigate
                            >
                                Competency Setup
                            </flux:sidebar.item>

                            <flux:sidebar.item
                                icon="academic-cap"
                                :href="route('certification.index')"
                                :current="request()->routeIs('certification.*')"
                                wire:navigate
                            >
                                Certification Management
                            </flux:sidebar.item>
                        @endif

                        @if ($user->hasRole('registrar', 'focal_person'))
                            <flux:sidebar.item
                                icon="chart-bar"
                                :href="route('reports.index')"
                                :current="request()->routeIs('reports.*')"
                                wire:navigate
                            >
                                Reports & Oversight
                            </flux:sidebar.item>

                            <flux:sidebar.item
                                icon="clipboard-document-list"
                                :href="route('announcements.index')"
                                :current="request()->routeIs('announcements.*')"
                                wire:navigate
                            >
                                Announcements
                            </flux:sidebar.item>
                        @endif

                        @if ($user->hasRole('applicant', 'scholar'))
                            <flux:sidebar.item
                                icon="clipboard-document-list"
                                :href="route('learner.registration')"
                                :current="request()->routeIs('learner.registration')"
                                wire:navigate
                            >
                                Registration Form
                            </flux:sidebar.item>

                            <flux:sidebar.item
                                icon="academic-cap"
                                :href="route('learner.portal')"
                                :current="request()->routeIs('learner.portal')"
                                wire:navigate
                            >
                                My Learning Portal
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />
            <div data-user-guide-sidebar class="px-3 pb-3">
                <flux:sidebar.item
                    icon="book-open"
                    :href="route('user-guide')"
                    :current="request()->routeIs('user-guide')"
                    wire:navigate
                >
                    User Guide &amp; Role Handbook
                </flux:sidebar.item>
            </div>


            <div class="border-t border-white/10 p-4">
                <div class="flex items-center gap-3 rounded-xl bg-white/10 p-3">
                    <flux:avatar
                        :name="$user->name"
                        :initials="$user->initials()"
                        class="bg-cyan-400 text-[#102f68]"
                    />

                    <div class="min-w-0 flex-1">
                        <p class="tesda-user-name truncate text-sm font-semibold">
                            {{ $user->name }}
                        </p>

                        <p class="tesda-user-role truncate text-xs">
                            {{ $user->roleLabel() }}
                        </p>
                    </div>
                </div>

                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf

                    <button
                        type="submit"
                        class="tesda-logout flex w-full items-center justify-center gap-2 rounded-lg border border-white/20 px-3 py-2 text-sm font-semibold transition hover:bg-white/10"
                    >
                        <flux:icon icon="arrow-right-start-on-rectangle" class="size-4" />
                        Sign out
                    </button>
                </form>
            </div>
        </flux:sidebar>

        <flux:header class="border-b border-slate-200 bg-white px-4 shadow-sm lg:px-7">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-3" inset="left" />

            <div class="hidden items-center gap-2 text-sm text-slate-500 md:flex">
                <span>TESDAConnect</span>
                <span class="text-slate-300">/</span>
                <span class="font-semibold text-slate-700">
                    {{ $title ?? 'Dashboard' }}
                </span>
            </div>

            <flux:spacer />

            <div class="hidden items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 sm:flex">
                <span class="size-2 rounded-full bg-emerald-500"></span>
                Online
            </div>

            <a
                href="{{ route('profile.edit') }}"
                class="ml-3 flex items-center gap-2 rounded-xl p-1.5 transition hover:bg-slate-100"
                wire:navigate
            >
                <flux:avatar
                    :name="$user->name"
                    :initials="$user->initials()"
                    class="bg-cyan-500 text-white"
                />

                <span class="hidden text-left sm:grid">
                    <span class="text-sm font-semibold leading-tight">
                        {{ $user->name }}
                    </span>

                    <span class="text-xs text-slate-500">
                        {{ $user->roleLabel() }}
                    </span>
                </span>
            </a>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist


        <script>
            (() => {
                const storageKey = @js('tesda-connect-v2:sidebar-groups:' . (string) $user->getAuthIdentifier());
                const rootSelector = '.tesda-sidebar';
                const globalKey = '__tesdaConnectSidebarGroupPersistenceV2';

                // The layout may be visited again through Livewire wire:navigate.
                // Keep one delegated listener while restoring the saved state on each navigation.
                if (window[globalKey]) {
                    window[globalKey].restore();
                    return;
                }

                let restoring = false;

                const readState = () => {
                    try {
                        const value = JSON.parse(window.localStorage.getItem(storageKey) || '{}');
                        if (!value || typeof value !== 'object') return { openGroup: null };

                        // Backward-compatible migration from the previous { groupId: true/false } format.
                        if (typeof value.openGroup === 'string' || value.openGroup === null) {
                            return { openGroup: value.openGroup || null };
                        }

                        const legacyOpenGroup = Object.keys(value).find((key) => value[key] === true);
                        return { openGroup: legacyOpenGroup || null };
                    } catch (error) {
                        return { openGroup: null };
                    }
                };

                const writeState = (openGroup) => {
                    try {
                        window.localStorage.setItem(storageKey, JSON.stringify({ openGroup: openGroup || null }));
                    } catch (error) {
                        // If storage is disabled, the sidebar still works for the current page.
                    }
                };

                const groups = () => Array.from(document.querySelectorAll(`${rootSelector} [data-tesda-sidebar-group]`));
                const findTrigger = (group) => group.querySelector('button[aria-expanded], [role="button"][aria-expanded]');

                const setExpanded = (group, shouldExpand) => {
                    const trigger = findTrigger(group);
                    if (!trigger) return;

                    const isExpanded = trigger.getAttribute('aria-expanded') === 'true';
                    if (shouldExpand !== isExpanded) {
                        restoring = true;
                        trigger.click();
                        restoring = false;
                    }
                };

                const restore = () => {
                    window.requestAnimationFrame(() => {
                        const state = readState();
                        const availableGroups = groups();
                        const savedOpenGroup = availableGroups.some((group) => group.getAttribute('data-tesda-sidebar-group') === state.openGroup)
                            ? state.openGroup
                            : null;

                        // Accordion behavior: restore exactly one open section; every other section stays closed.
                        availableGroups.forEach((group) => {
                            const id = group.getAttribute('data-tesda-sidebar-group');
                            setExpanded(group, id === savedOpenGroup);
                        });

                        // Normalize older saved state to the exclusive single-open format.
                        if (savedOpenGroup !== state.openGroup) writeState(savedOpenGroup);
                    });
                };

                document.addEventListener('click', (event) => {
                    if (!(event.target instanceof Element)) return;

                    const trigger = event.target.closest(
                        `${rootSelector} [data-tesda-sidebar-group] button[aria-expanded], ` +
                        `${rootSelector} [data-tesda-sidebar-group] [role="button"][aria-expanded]`
                    );

                    if (!trigger || restoring) return;

                    const group = trigger.closest('[data-tesda-sidebar-group]');
                    const id = group?.getAttribute('data-tesda-sidebar-group');
                    if (!id) return;

                    // Wait until Flux toggles the clicked group, then close every other section.
                    window.setTimeout(() => {
                        const isExpanded = trigger.getAttribute('aria-expanded') === 'true';
                        if (isExpanded) {
                            groups().forEach((otherGroup) => {
                                if (otherGroup !== group) setExpanded(otherGroup, false);
                            });
                            writeState(id);
                        } else {
                            writeState(null);
                        }
                    }, 0);
                });

                window[globalKey] = { restore };
                document.addEventListener('livewire:navigated', restore);
                document.addEventListener('DOMContentLoaded', restore, { once: true });
                if (document.readyState !== 'loading') restore();
            })();
        </script>

        @include('layouts.app.partials.scroll-controls')
        @fluxScripts
    </body>
</html>
