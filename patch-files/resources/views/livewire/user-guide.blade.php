<?php

use function Livewire\Volt\{layout, title};

layout('layouts::app');
title('User Guide & Role Handbook');

?>

@php
    $activeRole = auth()->user()->role;
    $roleLabels = [
        'administrator' => 'System Administrator',
        'registrar' => 'Registrar',
        'focal_person' => 'UTPRAS / Focal Person',
        'applicant' => 'Applicant',
        'scholar' => 'Scholar / Student',
    ];
    $activeRoleLabel = $roleLabels[$activeRole] ?? auth()->user()->roleLabel();
@endphp

<div class="mx-auto max-w-7xl space-y-8 pb-12">
    <section class="relative isolate overflow-hidden rounded-3xl bg-gradient-to-br from-[#0b1f45] via-[#12356d] to-[#087e9b] px-6 py-8 text-white shadow-xl sm:px-10 sm:py-11">
        <div class="pointer-events-none absolute -right-12 -top-16 h-64 w-64 rounded-full border-[32px] border-white/5"></div>
        <div class="pointer-events-none absolute -bottom-28 right-28 h-64 w-64 rounded-full bg-cyan-400/10 blur-2xl"></div>
        <div class="relative max-w-3xl">
            <div class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-bold uppercase tracking-[0.18em] text-cyan-100">
                <span aria-hidden="true">✦</span> TESDA Connect Help Center
            </div>
            <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-5xl">User Guide & Role Handbook</h1>
            <p class="mt-4 max-w-2xl text-sm leading-7 text-blue-100 sm:text-base">
                A simple guide to what you can do, what you should do next, and where to find each task in TESDA Connect.
            </p>
            <div class="mt-6 inline-flex items-center gap-3 rounded-2xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur">
                <span class="grid h-10 w-10 place-items-center rounded-xl bg-amber-400 text-lg font-black text-[#102f68]">{{ strtoupper(substr($activeRoleLabel, 0, 1)) }}</span>
                <span>
                    <span class="block text-xs font-medium text-blue-100">You are signed in as</span>
                    <span class="mt-0.5 block text-sm font-extrabold">{{ $activeRoleLabel }}</span>
                </span>
            </div>
        </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5" aria-label="Guide sections">
        @foreach ([
            ['administrator', 'Administrator', 'System oversight and controls', '⚙', '#administrator'],
            ['registrar', 'Registrar', 'Enrollment and student records', '✓', '#registrar'],
            ['focal_person', 'UTPRAS / Focal Person', 'Training and competency progress', '▥', '#focal-person'],
            ['applicant', 'Applicant', 'Registration and requirements', '✎', '#applicant'],
            ['scholar', 'Scholar / Student', 'Classes, progress and notices', '⌂', '#scholar'],
        ] as [$role, $label, $description, $symbol, $anchor])
            <a href="{{ $anchor }}" class="group rounded-2xl border p-4 transition hover:-translate-y-0.5 hover:shadow-md {{ $activeRole === $role ? 'border-cyan-500 bg-cyan-50 ring-2 ring-cyan-100' : 'border-slate-200 bg-white hover:border-slate-300' }}">
                <div class="flex items-center justify-between gap-3">
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-[#102f68] text-lg font-black text-white">{{ $symbol }}</span>
                    @if ($activeRole === $role)
                        <span class="rounded-full bg-cyan-700 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-white">Your role</span>
                    @else
                        <span class="text-slate-400 transition group-hover:translate-x-1" aria-hidden="true">→</span>
                    @endif
                </div>
                <h2 class="mt-4 text-sm font-extrabold text-slate-900">{{ $label }}</h2>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $description }}</p>
            </a>
        @endforeach
    </section>

    <section id="start-here" class="grid gap-5 lg:grid-cols-[1.15fr_.85fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-7">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">Start here</p>
            <h2 class="mt-2 text-2xl font-black tracking-tight text-[#102f68]">How the workflow fits together</h2>
            <p class="mt-3 text-sm leading-6 text-slate-600">Each role has its own responsibilities. Follow the process in order and use the same learner record throughout the enrollment journey.</p>
            <div class="mt-6 space-y-3">
                @foreach ([
                    ['01', 'Applicant / Student', 'Complete the registration form and upload the required documents.'],
                    ['02', 'Registrar', 'Review the submission, check requirements, return missing items, verify and confirm when ready.'],
                    ['03', 'UTPRAS / Focal Person', 'Coordinate the assigned institution, schedule training and record competency progress.'],
                    ['04', 'Administrator', 'Oversee records, accounts, institutions, system activity and reports. Enrollment records are view-only for Admin.'],
                    ['05', 'Scholar', 'Follow the schedule, track progress, read announcements and access available certificates.'],
                ] as [$number, $title, $copy])
                    <div class="flex gap-3 rounded-xl bg-slate-50 p-3.5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[#102f68] text-xs font-black text-white">{{ $number }}</span>
                        <div><h3 class="text-sm font-bold text-slate-900">{{ $title }}</h3><p class="mt-1 text-sm leading-5 text-slate-600">{{ $copy }}</p></div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 sm:p-7">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-800">Important reminders</p>
            <h2 class="mt-2 text-xl font-black text-amber-950">Keep records safe and accurate</h2>
            <ul class="mt-5 space-y-3 text-sm leading-6 text-amber-950">
                <li class="flex gap-2"><span class="font-black">•</span><span>Only work on records and institutions that your role is authorized to access.</span></li>
                <li class="flex gap-2"><span class="font-black">•</span><span>Review a document before marking it verified. Never mark an unseen or unreadable file as approved.</span></li>
                <li class="flex gap-2"><span class="font-black">•</span><span>Submitted or confirmed enrollment details may be locked. Contact the Registrar if a correction is needed.</span></li>
                <li class="flex gap-2"><span class="font-black">•</span><span>Do not share passwords or learner documents outside the authorized TESDA Connect workflow.</span></li>
                <li class="flex gap-2"><span class="font-black">•</span><span>Use the toast notification to understand whether an action succeeded or needs attention. Form field validation messages remain beside the fields.</span></li>
            </ul>
        </div>
    </section>

    <section id="administrator" class="scroll-mt-6 rounded-2xl border bg-white p-6 shadow-sm sm:p-8 {{ $activeRole === 'administrator' ? 'border-cyan-400 ring-2 ring-cyan-100' : 'border-slate-200' }}">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div><p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">Role 01</p><h2 class="mt-1 text-2xl font-black text-[#102f68]">System Administrator</h2><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Your job is to oversee the system and maintain its configuration. The Administrator can view submitted enrollment records, but does not process or change them.</p></div>
            @if ($activeRole === 'administrator') <span class="w-fit rounded-full bg-cyan-100 px-3 py-1.5 text-xs font-extrabold text-cyan-900">Your role</span> @endif
        </div>
        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                ['Dashboard & Oversight', 'Review enrollment totals, batch overview, record completeness, activity and summary reports.'],
                ['User Accounts', 'Create or manage authorized accounts, assign roles and institutions, reset access and deactivate accounts when necessary.'],
                ['Institutions, Courses & Batches', 'Maintain institution records and manage course and training-batch setup. Check information before changing live records.'],
                ['System Monitoring', 'Review audit activity, announcement monitoring, database/backup health and available system status information.'],
                ['Schedules & Reports', 'Review schedules and reports across the scope allowed to your Administrator account.'],
                ['Enrollment Records — View Only', 'Inspect submitted student information for oversight. Do not verify documents, return applications, confirm enrollments or edit student submissions.'],
            ] as [$heading, $body])
                <div class="rounded-xl border border-slate-200 p-4"><h3 class="font-extrabold text-slate-900">{{ $heading }}</h3><p class="mt-2 text-sm leading-6 text-slate-600">{{ $body }}</p></div>
            @endforeach
        </div>
        <div class="mt-5 rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-700"><strong>Good practice:</strong> use System Activity/Audit Logs to investigate changes. Prefer deactivating an account over permanently deleting a user who has learner records.</div>
    </section>

    <section id="registrar" class="scroll-mt-6 rounded-2xl border bg-white p-6 shadow-sm sm:p-8 {{ $activeRole === 'registrar' ? 'border-cyan-400 ring-2 ring-cyan-100' : 'border-slate-200' }}">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">Role 02</p><h2 class="mt-1 text-2xl font-black text-[#102f68]">Registrar</h2><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">You manage intake and official student records. Process applications in sequence and make sure documents and required fields are complete before verification.</p></div>@if ($activeRole === 'registrar') <span class="w-fit rounded-full bg-cyan-100 px-3 py-1.5 text-xs font-extrabold text-cyan-900">Your role</span> @endif</div>
        <div class="mt-6 grid gap-4 md:grid-cols-2">
            <div class="rounded-xl bg-blue-50 p-5"><h3 class="font-extrabold text-blue-950">Enrollment review checklist</h3><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-blue-950"><li>Open the submitted learner record and review the form.</li><li>Open each uploaded document and check that it is readable and belongs to the learner.</li><li>Approve valid documents and record accurate remarks for missing or rejected items.</li><li>Return the application for revision when the learner needs to correct or upload something.</li><li>Verify only when all required profile fields and applicable documents are approved.</li><li>Confirm only a verified application. Check the institution/course, then confirm to issue the Scholar ID and lock the enrollment record.</li></ol></div>
            <div class="rounded-xl bg-emerald-50 p-5"><h3 class="font-extrabold text-emerald-950">Document requirements</h3><div class="mt-3 space-y-3 text-sm leading-6 text-emerald-950"><div><strong>Mandatory:</strong> TESDA Learner Profile / Registration Form (MIS 03-01, Version 2021) and Birth Certificate.</div><div><strong>Conditional:</strong> Transcript of Records (TOR) for a high-school/college graduate, according to the application's rules; Marriage Contract if the learner is married.</div><div class="rounded-lg border border-emerald-200 bg-white/70 p-3">A “Birth certificate must be verified” warning means the document must be reviewed and marked approved before the application can be verified.</div></div></div>
        </div>
        <div class="mt-5 grid gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-slate-200 p-4"><h3 class="font-extrabold">Student Records</h3><p class="mt-2 text-sm leading-6 text-slate-600">Search the master list, review profiles, make permitted corrections with an edit reason, and archive or restore records following local policy.</p></div>
            <div class="rounded-xl border border-slate-200 p-4"><h3 class="font-extrabold">Intake & Quotas</h3><p class="mt-2 text-sm leading-6 text-slate-600">Open or close an intake, monitor application counts and quota remaining by course, then forward confirmed batches for Administrator oversight when ready.</p></div>
            <div class="rounded-xl border border-slate-200 p-4"><h3 class="font-extrabold">Announcements & Accounts</h3><p class="mt-2 text-sm leading-6 text-slate-600">Use available announcement tools and authorized account controls. Change passwords securely and deactivate access for former staff.</p></div>
        </div>
        <p class="mt-5 text-sm leading-6 text-slate-600"><strong>Remember:</strong> the Administrator has a view-only enrollment role. Registrar actions such as verify, return for revision and confirm belong to the Registrar workflow.</p>
    </section>

    <section id="focal-person" class="scroll-mt-6 rounded-2xl border bg-white p-6 shadow-sm sm:p-8 {{ $activeRole === 'focal_person' ? 'border-cyan-400 ring-2 ring-cyan-100' : 'border-slate-200' }}">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">Role 03</p><h2 class="mt-1 text-2xl font-black text-[#102f68]">UTPRAS / Focal Person</h2><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Coordinate training operations for the institution or institutions assigned to your account. Record progress accurately and keep supporting documents organized.</p></div>@if ($activeRole === 'focal_person') <span class="w-fit rounded-full bg-cyan-100 px-3 py-1.5 text-xs font-extrabold text-cyan-900">Your role</span> @endif</div>
        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                ['Dashboard', 'Check your assigned institution(s), pending competency entries, recent enrollments ready for scheduling and report deadlines.'],
                ['Competency Progress', 'Choose the trainee and competency task, update the status, and use batch updates carefully. Review the chart against the supporting physical record.'],
                ['Review & Finalize', 'Filter progress by unit, trainee or status. Verify a unit only after checking it; verified units may be locked against further changes.'],
                ['Course Management', 'Maintain course code, title, qualification level, duration, requirements, curriculum reference and active/archive status within your authorized scope.'],
                ['Schedules & Batch Coordination', 'Check the batch, class date/time, venue and start/end details. Coordinate changes with the authorized staff and notify learners through the approved channel.'],
                ['Certification', 'Review the completion status. Issue a certificate only for a learner marked Completed. Certificates show identity, course, institution, dates and completion status—not grades or scores.'],
                ['Documents & Forms', 'Upload categorized attendance sheets, assessment forms and photos. Use available TESDA templates and generate attendance, enrollment and progress summaries.'],
                ['Reports & Outgoing Documents', 'Prepare progress summaries, keep a record of outgoing documents and retain reference numbers for later follow-up.'],
                ['Offline Work', 'Offline queues are stored on the browser/device. Reconnect and allow the application to sync; check the sync result before assuming a record was saved centrally.'],
            ] as [$heading, $body])
                <div class="rounded-xl border border-slate-200 p-4"><h3 class="font-extrabold text-slate-900">{{ $heading }}</h3><p class="mt-2 text-sm leading-6 text-slate-600">{{ $body }}</p></div>
            @endforeach
        </div>
    </section>

    <section id="applicant" class="scroll-mt-6 rounded-2xl border bg-white p-6 shadow-sm sm:p-8 {{ $activeRole === 'applicant' ? 'border-cyan-400 ring-2 ring-cyan-100' : 'border-slate-200' }}">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">Role 04</p><h2 class="mt-1 text-2xl font-black text-[#102f68]">Applicant</h2><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Your main task is to submit a complete and accurate TESDA registration. Use your own account and enter information exactly as shown on your supporting documents.</p></div>@if ($activeRole === 'applicant') <span class="w-fit rounded-full bg-cyan-100 px-3 py-1.5 text-xs font-extrabold text-cyan-900">Your role</span> @endif</div>
        <div class="mt-6 grid gap-4 lg:grid-cols-[1fr_1fr]">
            <div class="rounded-xl border border-slate-200 p-5"><h3 class="font-extrabold text-slate-900">Before you submit</h3><ul class="mt-3 space-y-2 text-sm leading-6 text-slate-600"><li>✓ Select the correct institution and course/qualification.</li><li>✓ Complete personal and learner/manpower profile information.</li><li>✓ Select applicable learner/trainee classifications and scholarship information.</li><li>✓ Read and provide privacy consent/disclaimer as requested.</li><li>✓ Upload a clear Birth Certificate and any applicable conditional documents.</li><li>✓ Check the form carefully before submitting.</li></ul></div>
            <div class="rounded-xl border border-slate-200 p-5"><h3 class="font-extrabold text-slate-900">After you submit</h3><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-slate-600"><li>Open your Learning Portal to check the current enrollment status and announcements.</li><li>If the Registrar returns the application for revision, follow the remarks and upload the corrected information or document.</li><li>Wait for the Registrar's document review and verification. Uploading a document does not mean it is already approved.</li><li>After confirmation, your official Scholar ID and learner status will be available when recorded by the system.</li></ol><p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm leading-6 text-amber-900"><strong>Locked record:</strong> if your enrollment is already submitted or finalized, the form may prevent further edits. Contact the Registrar for a correction instead of creating another registration.</p></div>
        </div>
    </section>

    <section id="scholar" class="scroll-mt-6 rounded-2xl border bg-white p-6 shadow-sm sm:p-8 {{ $activeRole === 'scholar' ? 'border-cyan-400 ring-2 ring-cyan-100' : 'border-slate-200' }}">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">Role 05</p><h2 class="mt-1 text-2xl font-black text-[#102f68]">Scholar / Student</h2><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Use your Learning Portal as your personal training overview. Check the schedule and your progress regularly.</p></div>@if ($activeRole === 'scholar') <span class="w-fit rounded-full bg-cyan-100 px-3 py-1.5 text-xs font-extrabold text-cyan-900">Your role</span> @endif</div>
        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                ['Check your status', 'Review whether your status is Not Started, In Training, Completed or Not Completed. If something appears wrong, contact the Registrar or assigned training staff.'],
                ['Check your next class', 'Confirm the date, time, venue and batch shown in the portal. If the section says To be announced, ask the training staff for an update.'],
                ['Track competency progress', 'Review completed and remaining competencies where the portal provides them. Report an incorrect entry to the assigned Focal Person.'],
                ['Follow deadlines', 'Read submission due dates and complete requested tasks on time. Staff must publish/update the relevant deadline for it to appear.'],
                ['Read announcements', 'Open current announcements, check attached information and mark an announcement as read if that option is shown.'],
                ['Access certificates', 'When the authorized staff issues a certificate, use the available download/view action. Certificate completion details should not include grades, scores or ratings.'],
            ] as [$heading, $body])
                <div class="rounded-xl border border-slate-200 p-4"><h3 class="font-extrabold text-slate-900">{{ $heading }}</h3><p class="mt-2 text-sm leading-6 text-slate-600">{{ $body }}</p></div>
            @endforeach
        </div>
    </section>

    <section id="statuses" class="scroll-mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-700">Quick reference</p>
        <h2 class="mt-1 text-2xl font-black text-[#102f68]">Understand the statuses</h2>
        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['Not Started', 'Training has not begun for the learner/batch.'],
                ['In Training', 'The learner is currently taking the course.'],
                ['Completed', 'Training/competency completion has been recorded by the authorized staff.'],
                ['Not Completed', 'Required training or competencies remain incomplete; check the recorded reason or pending units.'],
            ] as [$status, $description])
                <div class="rounded-xl bg-slate-50 p-4"><h3 class="font-extrabold text-slate-900">{{ $status }}</h3><p class="mt-2 text-sm leading-5 text-slate-600">{{ $description }}</p></div>
            @endforeach
        </div>
        <div class="mt-5 overflow-hidden rounded-xl border border-slate-200">
            <div class="bg-slate-100 px-4 py-3 text-sm font-extrabold text-slate-800">Enrollment workflow</div>
            <div class="grid gap-0 divide-y divide-slate-200 sm:grid-cols-2 sm:divide-y-0 sm:divide-x"><div class="p-4"><p class="font-bold text-slate-900">Draft / Submitted / Under Review</p><p class="mt-1 text-sm leading-5 text-slate-600">Form is being prepared or reviewed by the Registrar.</p></div><div class="p-4"><p class="font-bold text-slate-900">Returned for Revision</p><p class="mt-1 text-sm leading-5 text-slate-600">The learner must follow the Registrar's remarks and provide corrections.</p></div><div class="p-4"><p class="font-bold text-slate-900">Verified</p><p class="mt-1 text-sm leading-5 text-slate-600">Required fields and applicable documents have passed review.</p></div><div class="p-4"><p class="font-bold text-slate-900">Confirmed</p><p class="mt-1 text-sm leading-5 text-slate-600">The Registrar has confirmed the application; the official Scholar ID is issued and the record is locked.</p></div></div>
        </div>
    </section>

    <section id="help" class="scroll-mt-6 rounded-2xl bg-[#102f68] p-6 text-white shadow-lg sm:p-8">
        <div class="grid gap-5 md:grid-cols-[1fr_auto] md:items-center">
            <div><p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-200">Need help?</p><h2 class="mt-2 text-2xl font-black">What should I do if something is missing?</h2><p class="mt-2 max-w-3xl text-sm leading-6 text-blue-100">A missing institution or course often means the account assignment or course setup needs review. A missing class schedule or deadline means authorized staff may still need to enter it. For access errors, contact your system administrator. For enrollment corrections, contact the Registrar.</p></div>
            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-400 px-5 py-3 text-sm font-extrabold text-[#102f68] transition hover:bg-amber-300">Back to Dashboard <span aria-hidden="true">→</span></a>
        </div>
    </section>
    <p class="text-center text-xs leading-5 text-slate-400">TESDA Connect User Guide · Use the latest in-app instructions and follow your institution's current procedures.</p>
</div>
