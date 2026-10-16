<?php

namespace App\Http\Controllers;

use App\Models\EnrollmentApplication;
use App\Models\TrainingBatch;
use App\Support\InstitutionScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FocalAdminTaskController extends Controller
{
    private const FORMS = [
        'progress-chart-template', 'tesda-form-template', 'batch-summary', 'enrollment-summary',
        'attendance-sheet', 'progress-report', 'trainee-roster', 'meeting-minutes-template',
        'daily-time-record-template',
    ];

    public function download(Request $request, string $form)
    {
        abort_unless($request->user()?->account_status === 'active' && $request->user()->hasRole('focal_person'), 403);
        abort_unless(in_array($form, self::FORMS, true), 404);
        [$title, $headers, $rows] = $this->dataFor($form, $request);
        $filename = 'tesda-'.str_replace('_', '-', $form).'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) return;
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($value) => $this->safeCell($value), $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function print(Request $request, string $form)
    {
        abort_unless($request->user()?->account_status === 'active' && $request->user()->hasRole('focal_person'), 403);
        abort_unless(in_array($form, self::FORMS, true), 404);
        [$title, $headers, $rows] = $this->dataFor($form, $request);
        return view('focal.print-form', [
            'formTitle' => $title,
            'headers' => $headers,
            'rows' => array_slice($rows, 0, 500),
            'institutionName' => $request->user()->institutions()->orderBy('institutions.name')->value('institutions.name') ?? 'Assigned Institution',
            'generatedAt' => now(),
        ]);
    }

    private function dataFor(string $form, Request $request): array
    {
        $institutionIds = InstitutionScope::idsFor($request->user())->map(fn ($id) => (int) $id)->all();
        $batchId = $request->integer('batch_id') ?: null;
        if ($batchId) {
            abort_unless(TrainingBatch::query()->whereKey($batchId)->whereIn('institution_id', $institutionIds)->exists(), 403);
        }

        if (in_array($form, ['progress-chart-template', 'meeting-minutes-template', 'daily-time-record-template'], true)) {
            return match ($form) {
                'progress-chart-template' => ['Blank Competency Progress Chart Template', ['Institution', 'Course Code', 'Course', 'Scholar ID', 'Trainee Name', 'Unit No.', 'Unit', 'Task No.', 'Task', 'Status', 'Assessed By', 'Assessed At'], []],
                'meeting-minutes-template' => ['Meeting Minutes Template', ['Meeting Date', 'Start Time', 'End Time', 'Venue', 'Attendees', 'Agenda Item', 'Discussion / Decisions', 'Action Owner', 'Due Date'], []],
                default => ['Daily Time Record Template', ['Date', 'Time In', 'Break Out', 'Break In', 'Time Out', 'Total Hours', 'Employee / Trainee', 'Signature', 'Remarks'], []],
            };
        }

        if ($form === 'tesda-form-template') {
            return ['TESDA Forms Checklist Template (confirm current official revision before filing)', ['Form / Document', 'Purpose', 'Institution', 'Course / Batch', 'Prepared By', 'Date Prepared', 'Remarks'], [
                ['Enrollment Summary', 'Summary of submitted and confirmed learner records', '', '', '', '', 'Verify required TESDA form revision before official filing.'],
                ['Attendance Sheet', 'Daily presence record of assigned trainees', '', '', '', '', 'Use local institution procedures and current official template.'],
                ['Competency Progress Report', 'Progress and assessment status by competency task', '', '', '', '', 'This is a working template, not a certified TESDA-issued form.'],
            ]];
        }

        if ($form === 'batch-summary') {
            $rows = DB::table('training_batches as tb')
                ->join('institutions as i', 'i.id', '=', 'tb.institution_id')
                ->leftJoin('courses as c', 'c.id', '=', 'tb.course_id')
                ->leftJoin('enrollment_applications as ea', function ($join) { $join->on('ea.training_batch_id', '=', 'tb.id')->where('ea.application_status', '=', 'confirmed'); })
                ->whereIn('tb.institution_id', $institutionIds)
                ->when($batchId, fn ($query) => $query->where('tb.id', $batchId))
                ->groupBy('tb.id', 'i.code', 'c.code', 'c.name', 'tb.code', 'tb.name', 'tb.capacity', 'tb.starts_at', 'tb.ends_at', 'tb.status')
                ->orderBy('i.code')->orderBy('tb.starts_at')
                ->get(['i.code as institution', 'c.code as course_code', 'c.name as course', 'tb.code as batch_code', 'tb.name as batch', 'tb.capacity', 'tb.starts_at', 'tb.ends_at', 'tb.status', DB::raw('COUNT(ea.id) as confirmed_trainees')])
                ->map(fn ($r) => [(string) $r->institution, (string) $r->course_code, (string) $r->course, (string) $r->batch_code, (string) $r->batch, (int) $r->capacity, (string) $r->starts_at, (string) $r->ends_at, (string) $r->status, (int) $r->confirmed_trainees, max(0, (int) $r->capacity - (int) $r->confirmed_trainees)])
                ->all();
            return ['Training Batch Summary', ['Institution', 'Course Code', 'Course', 'Batch Code', 'Batch', 'Capacity', 'Start Date', 'End Date', 'Status', 'Confirmed Trainees', 'Slots Remaining'], $rows];
        }

        if (in_array($form, ['enrollment-summary', 'trainee-roster'], true)) {
            $rows = DB::table('enrollment_applications as ea')
                ->join('institutions as i', 'i.id', '=', 'ea.institution_id')
                ->leftJoin('courses as c', 'c.id', '=', 'ea.course_id')
                ->leftJoin('training_batches as tb', 'tb.id', '=', 'ea.training_batch_id')
                ->whereIn('ea.institution_id', $institutionIds)
                ->whereNull('ea.archived_at')
                ->where('ea.application_status', 'confirmed')
                ->when($batchId, fn ($query) => $query->where('ea.training_batch_id', $batchId))
                ->orderBy('i.code')->orderBy('ea.learner_name')
                ->get(['i.code as institution', 'ea.reference_no', 'ea.scholar_id', 'ea.learner_name', 'ea.email', 'ea.contact_number', 'c.code as course_code', 'c.name as course', 'tb.code as batch_code', 'tb.name as batch', 'ea.application_status', 'ea.confirmed_at']);
            if ($form === 'trainee-roster') {
                return ['Confirmed Trainee Roster', ['Institution', 'Scholar ID', 'Trainee Name', 'Course', 'Batch', 'Contact Number', 'Email'], $rows->map(fn ($r) => [(string) $r->institution, (string) $r->scholar_id, (string) $r->learner_name, (string) $r->course, (string) $r->batch, (string) $r->contact_number, (string) $r->email])->all()];
            }
            return ['Enrollment Summary', ['Institution', 'Reference Number', 'Scholar ID', 'Learner Name', 'Email', 'Contact Number', 'Course Code', 'Course', 'Batch Code', 'Batch', 'Status', 'Confirmed At'], $rows->map(fn ($r) => [(string) $r->institution, (string) $r->reference_no, (string) $r->scholar_id, (string) $r->learner_name, (string) $r->email, (string) $r->contact_number, (string) $r->course_code, (string) $r->course, (string) $r->batch_code, (string) $r->batch, (string) $r->application_status, (string) $r->confirmed_at])->all()];
        }

        if ($form === 'attendance-sheet') {
            $rows = DB::table('enrollment_applications as ea')
                ->join('institutions as i', 'i.id', '=', 'ea.institution_id')
                ->leftJoin('courses as c', 'c.id', '=', 'ea.course_id')
                ->leftJoin('training_batches as tb', 'tb.id', '=', 'ea.training_batch_id')
                ->leftJoin('attendance_records as ar', function ($join) use ($request) {
                    $join->on('ar.enrollment_application_id', '=', 'ea.id');
                    if ($request->filled('attendance_date')) $join->whereDate('ar.attendance_date', '=', $request->input('attendance_date'));
                })
                ->whereIn('ea.institution_id', $institutionIds)->where('ea.application_status', 'confirmed')->whereNull('ea.archived_at')
                ->when($batchId, fn ($query) => $query->where('ea.training_batch_id', $batchId))
                ->orderBy('tb.code')->orderBy('ea.learner_name')
                ->get(['i.code as institution', 'c.name as course', 'tb.name as batch', 'ea.scholar_id', 'ea.learner_name', 'ar.attendance_date', 'ar.status as attendance_status', 'ar.remarks']);
            return ['Attendance Sheet'.($request->input('attendance_date') ? ' — '.$request->input('attendance_date') : ''), ['Institution', 'Course', 'Batch', 'Scholar ID', 'Trainee Name', 'Attendance Date', 'Status', 'Remarks', 'Signature'], $rows->map(fn ($r) => [(string) $r->institution, (string) $r->course, (string) $r->batch, (string) $r->scholar_id, (string) $r->learner_name, (string) $r->attendance_date, (string) $r->attendance_status, (string) $r->remarks, ''])->all()];
        }

        $rows = DB::table('enrollment_applications as ea')
            ->join('institutions as i', 'i.id', '=', 'ea.institution_id')
            ->leftJoin('courses as c', 'c.id', '=', 'ea.course_id')
            ->join('competency_units as cu', 'cu.course_id', '=', 'ea.course_id')
            ->join('competency_tasks as ct', function ($join) {
                $join->on('ct.competency_unit_id', '=', 'cu.id')->whereIn('ct.chart_type', ['progress', 'achievement']);
            })
            ->leftJoin('competency_progress as cp', function ($join) {
                $join->on('cp.enrollment_application_id', '=', 'ea.id')->on('cp.competency_task_id', '=', 'ct.id');
            })
            ->whereIn('ea.institution_id', $institutionIds)->where('ea.application_status', 'confirmed')->whereNull('ea.archived_at')
            ->when($batchId, fn ($query) => $query->where('ea.training_batch_id', $batchId))
            ->orderBy('i.code')->orderBy('ea.learner_name')->orderBy('cu.sort_order')->orderBy('ct.sort_order')
            ->get(['i.code as institution', 'ea.scholar_id', 'ea.learner_name', 'c.code as course_code', 'c.name as course', 'cu.unit_number', 'cu.name as unit', 'ct.task_number', 'ct.name as task', 'ct.chart_type', 'cp.status', 'cp.assessed_at']);
        return ['Competency Progress Report', ['Institution', 'Scholar ID', 'Trainee Name', 'Course Code', 'Course', 'Unit No.', 'Unit', 'Task No.', 'Task', 'Chart Type', 'Status', 'Assessed At'], $rows->map(fn ($r) => [(string) $r->institution, (string) $r->scholar_id, (string) $r->learner_name, (string) $r->course_code, (string) $r->course, (int) $r->unit_number, (string) $r->unit, (int) $r->task_number, (string) $r->task, (string) $r->chart_type, (string) ($r->status ?? 'not_assessed'), (string) $r->assessed_at])->all()];
    }

    private function safeCell(mixed $value): string
    {
        $cell = $value === null ? '' : (string) $value;
        return $cell !== '' && preg_match('/^[=+\-@\t\r]/u', $cell) === 1 ? "'".$cell : $cell;
    }
}
