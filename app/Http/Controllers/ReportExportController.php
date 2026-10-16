<?php

namespace App\Http\Controllers;

use App\Support\InstitutionScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    /**
     * Export staff-authorized operational data as CSV.
     * Institution scoping is applied server-side for every export type.
     */
    public function export(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['enrollments', 'competency-progress', 'attendance'], true), 404);

        $institutionIds = InstitutionScope::idsFor($request->user())->all();
        $filename = 'tesda-'.$type.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($type, $institutionIds): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            // UTF-8 BOM improves compatibility with Microsoft Excel.
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $this->headers($type));

            foreach ($this->rows($type, $institutionIds) as $row) {
                fputcsv($output, array_map([$this, 'safeCsvCell'], $row));
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return list<string> */
    private function headers(string $type): array
    {
        return match ($type) {
            'enrollments' => [
                'Reference Number', 'Scholar ID', 'Learner Name', 'Email', 'Contact Number',
                'Institution Code', 'Institution Name', 'Course Code', 'Course Name',
                'Application Status', 'Record Completeness (%)', 'Submitted At', 'Verified At', 'Confirmed At',
            ],
            'competency-progress' => [
                'Reference Number', 'Scholar ID', 'Learner Name', 'Institution Code', 'Institution Name',
                'Course Code', 'Course Name', 'Unit Number', 'Unit Name', 'Chart Type',
                'Task Number', 'Task', 'Competency Status', 'Assessed At',
            ],
            'attendance' => [
                'Reference Number', 'Scholar ID', 'Learner Name', 'Institution Code', 'Institution Name',
                'Course Code', 'Course Name', 'Attendance Date', 'Attendance Status', 'Remarks', 'Recorded By',
            ],
            default => abort(404),
        };
    }

    /** @return iterable<array<int, int|string|null>> */
    private function rows(string $type, array $institutionIds): iterable
    {
        if ($type === 'enrollments') {
            $query = DB::table('enrollment_applications as ea')
                ->join('institutions as i', 'i.id', '=', 'ea.institution_id')
                ->leftJoin('courses as c', 'c.id', '=', 'ea.course_id')
                ->whereIn('ea.institution_id', $institutionIds)
                ->orderBy('ea.id')
                ->select([
                    'ea.reference_no', 'ea.scholar_id', 'ea.learner_name', 'ea.email', 'ea.contact_number',
                    'i.code as institution_code', 'i.name as institution_name', 'c.code as course_code',
                    'c.name as course_name', 'ea.application_status', 'ea.record_completeness',
                    'ea.submitted_at', 'ea.verified_at', 'ea.confirmed_at',
                ]);

            foreach ($query->cursor() as $row) {
                yield [
                    $row->reference_no, $row->scholar_id, $row->learner_name, $row->email, $row->contact_number,
                    $row->institution_code, $row->institution_name, $row->course_code, $row->course_name,
                    $row->application_status, $row->record_completeness, $row->submitted_at, $row->verified_at,
                    $row->confirmed_at,
                ];
            }

            return;
        }

        if ($type === 'competency-progress') {
            $query = DB::table('enrollment_applications as ea')
                ->join('institutions as i', 'i.id', '=', 'ea.institution_id')
                ->leftJoin('courses as c', 'c.id', '=', 'ea.course_id')
                ->join('competency_units as cu', 'cu.course_id', '=', 'ea.course_id')
                ->join('competency_tasks as ct', 'ct.competency_unit_id', '=', 'cu.id')
                ->leftJoin('competency_progress as cp', function ($join): void {
                    $join->on('cp.enrollment_application_id', '=', 'ea.id')
                        ->on('cp.competency_task_id', '=', 'ct.id');
                })
                ->whereIn('ea.institution_id', $institutionIds)
                ->where('ea.application_status', 'confirmed')
                ->whereIn('ct.chart_type', ['progress', 'achievement'])
                ->orderBy('ea.id')->orderBy('cu.sort_order')->orderBy('ct.sort_order')
                ->select([
                    'ea.reference_no', 'ea.scholar_id', 'ea.learner_name', 'i.code as institution_code',
                    'i.name as institution_name', 'c.code as course_code', 'c.name as course_name',
                    'cu.unit_number', 'cu.name as unit_name', 'ct.chart_type', 'ct.task_number',
                    'ct.name as task_name', 'cp.status as competency_status', 'cp.assessed_at',
                ]);

            foreach ($query->cursor() as $row) {
                yield [
                    $row->reference_no, $row->scholar_id, $row->learner_name, $row->institution_code,
                    $row->institution_name, $row->course_code, $row->course_name, $row->unit_number,
                    $row->unit_name, $row->chart_type, $row->task_number, $row->task_name,
                    $row->competency_status ?? 'not_assessed', $row->assessed_at,
                ];
            }

            return;
        }

        if ($type === 'attendance') {
            $query = DB::table('attendance_records as ar')
                ->join('enrollment_applications as ea', 'ea.id', '=', 'ar.enrollment_application_id')
                ->join('institutions as i', 'i.id', '=', 'ea.institution_id')
                ->leftJoin('courses as c', 'c.id', '=', 'ea.course_id')
                ->leftJoin('users as u', 'u.id', '=', 'ar.recorded_by_user_id')
                ->whereIn('ea.institution_id', $institutionIds)
                ->orderBy('ar.attendance_date')->orderBy('ea.learner_name')
                ->select([
                    'ea.reference_no', 'ea.scholar_id', 'ea.learner_name', 'i.code as institution_code',
                    'i.name as institution_name', 'c.code as course_code', 'c.name as course_name',
                    'ar.attendance_date', 'ar.status as attendance_status', 'ar.remarks', 'u.name as recorded_by',
                ]);

            foreach ($query->cursor() as $row) {
                yield [
                    $row->reference_no, $row->scholar_id, $row->learner_name, $row->institution_code,
                    $row->institution_name, $row->course_code, $row->course_name, $row->attendance_date,
                    $row->attendance_status, $row->remarks, $row->recorded_by,
                ];
            }
        }
    }

    private function safeCsvCell(mixed $value): string
    {
        $cell = $value === null ? '' : (string) $value;

        // Avoid CSV formula injection when exported text is opened in spreadsheet software.
        if ($cell !== '' && preg_match('/^[=+\-@\t\r]/u', $cell) === 1) {
            return "'".$cell;
        }

        return $cell;
    }
}
