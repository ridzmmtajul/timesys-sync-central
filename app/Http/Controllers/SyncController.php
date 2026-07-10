<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\Office;
use App\Models\OfficeDivision;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\ScheduleType;
use App\Models\SyncLog;
use App\Models\WorkSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SyncController extends Controller
{
    // This project pushes local records that haven't been sent yet to the
    // timesys-v2 instance, which receives and upserts them.

    public function pushEmployees(Request $request)
    {
        return $this->pushModule(
            Employee::class,
            // No withTrashed() here: a soft-deleted employee (deleted_at set)
            // must never be pushed to timesys-v2. Eloquent's default
            // SoftDeletingScope excludes them from this query, and the same
            // default scope applies in pendingCounts(), so they simply never
            // count as pending rather than getting stuck.
            Employee::whereNull('synced_at')->with(['office', 'employmentType', 'position', 'officeDivision']),
            '/api/sync/receive-employees',
            'employees',
            fn ($emp) => [
                'employee_no'          => $emp->employee_no,
                'first_name'           => $emp->first_name,
                'middle_name'          => $emp->middle_name,
                'last_name'            => $emp->last_name,
                'name_ext'             => $emp->name_ext,
                'gender'               => $emp->gender,
                'contact_no'           => $emp->contact_no,
                'job_title'            => $emp->job_title,
                'is_active'            => $emp->is_active,
                'image'                => $emp->image,
                'signature'            => $emp->signature,
                'office_name'          => $emp->office?->name,
                'office_code'          => $emp->office?->code,
                'employment_type_name' => $emp->employmentType?->name,
                'position_name'        => $emp->position?->name,
                'office_division_name' => $emp->officeDivision?->name,
                'office_division_code' => $emp->officeDivision?->code,
            ],
            // Each new employee triggers several extra lookups/creates on the
            // receiving side (office, division, position, employment type),
            // so batches need to be smaller than the other modules to stay
            // under timesys-v2's execution time limit.
            chunkSize: 25,
            syncedFrom: $request->input('synced_from')
        );
    }

    public function pushOffices()
    {
        return $this->pushModule(
            Office::class,
            Office::whereNull('synced_at'),
            '/api/sync/receive-offices',
            'offices',
            fn ($office) => [
                'code'        => $office->code,
                'name'        => $office->name,
                'description' => $office->description,
                'prefix'      => $office->prefix,
            ]
        );
    }

    public function pushOfficeDivisions()
    {
        return $this->pushModule(
            OfficeDivision::class,
            OfficeDivision::whereNull('synced_at')->with('office'),
            '/api/sync/receive-office-divisions',
            'office_divisions',
            fn ($division) => [
                'code'        => $division->code,
                'name'        => $division->name,
                'description' => $division->description,
                'office_name' => $division->office?->name,
                'office_code' => $division->office?->code,
            ]
        );
    }

    public function pushWorkSchedules(Request $request)
    {
        return $this->pushModule(
            WorkSchedule::class,
            // Eager-loading 'employee' would otherwise silently return null
            // for a soft-deleted employee (Eloquent's default scope excludes
            // trashed rows from relations), sending employee_no as null and
            // making the record look unmatchable on the receiving side even
            // though the employee genuinely exists there.
            WorkSchedule::whereNull('synced_at')->with(['employee' => fn ($q) => $q->withTrashed(), 'schedule', 'scheduleType']),
            '/api/sync/receive-work-schedules',
            'work_schedules',
            fn ($ws) => [
                // Round-tripped back in error messages so a failure can be
                // looked up directly by primary key on this (pushing) side —
                // employee_no/name alone can't disambiguate when the same
                // employee has multiple work schedule rows.
                'source_id'          => $ws->id,
                'employee_no'        => $ws->employee?->employee_no,
                'employee_name'      => trim($ws->employee?->first_name . ' ' . $ws->employee?->last_name),
                // A deleted employee here should still land on the receiving
                // side, just flagged inactive rather than rejected outright.
                'employee_is_active' => $ws->employee ? ($ws->employee->is_active && !$ws->employee->trashed()) : null,
                'schedule_name'      => $ws->schedule?->name,
                'schedule_type_name' => $ws->scheduleType?->name,
                'timein_AM'          => $ws->timein_AM,
                'timeout_AM'         => $ws->timeout_AM,
                'timein_PM'          => $ws->timein_PM,
                'timeout_PM'         => $ws->timeout_PM,
                'from_date'          => $ws->from_date,
                'to_date'            => $ws->to_date,
                'is_others'          => $ws->is_others,
                'schedule_for'       => $ws->schedule_for,
                'days'               => $ws->days,
                'no_lunch_gap'       => $ws->no_lunch_gap,
            ],
            // Each record also resolves schedule + schedule_type on the
            // receiving side, so this needs a smaller batch than the default
            // to stay under timesys-v2's execution time limit.
            chunkSize: 40,
            syncedFrom: $request->input('synced_from')
        );
    }

    public function pushAttendances(Request $request)
    {
        return $this->pushModule(
            Attendance::class,
            // See pushWorkSchedules: withTrashed() keeps a soft-deleted
            // employee's employee_no from being silently nulled out.
            Attendance::whereNull('synced_at')->with(['employee' => fn ($q) => $q->withTrashed()]),
            '/api/sync/receive-attendances',
            'attendances',
            fn ($att) => [
                'employee_no'         => $att->employee?->employee_no,
                'employee_is_active'  => $att->employee ? ($att->employee->is_active && !$att->employee->trashed()) : null,
                'check_time'  => optional($att->check_time)->format('Y-m-d H:i:s'),
                'serial_no'   => $att->serial_no,
                'post_no'     => $att->post_no,
                'void'        => $att->void,
            ],
            // Attendance volumes are the largest of any module, and even
            // this lightweight per-record shape hits timesys-v2's execution
            // time limit past a few hundred rows in one request.
            chunkSize: 50,
            syncedFrom: $request->input('synced_from')
        );
    }

    public function pendingCounts(): JsonResponse
    {
        return response()->json([
            'employees'        => Employee::whereNull('synced_at')->count(),
            'offices'          => Office::whereNull('synced_at')->count(),
            'office_divisions' => OfficeDivision::whereNull('synced_at')->count(),
            'work_schedules'   => WorkSchedule::whereNull('synced_at')->count(),
            'attendances'      => Attendance::whereNull('synced_at')->count(),
        ]);
    }

    /**
     * Proxies timesys-v2's canonical biometric location list so the "Push
     * All" modal can offer a dropdown instead of a free-text field - a typo'd
     * value here becomes a new synced_from, which timesys-v2 uses to scope
     * employee_no uniqueness (see employee_offices on that side).
     */
    public function biometricLocations(): JsonResponse
    {
        $targetUrl = config('services.sync.target_url');
        $apiKey    = config('services.sync.api_key');

        if (!$targetUrl || !$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'Sync not configured. Set SYNC_TARGET_URL and SYNC_API_KEY in .env.',
            ], 500);
        }

        try {
            $response = Http::withToken($apiKey)->timeout(15)->get(rtrim($targetUrl, '/') . '/api/sync/biometric-locations');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to reach timesys-v2.',
            ], 502);
        }

        if ($response->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'timesys-v2 returned an error.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data'    => $response->json('data', []),
        ]);
    }

    /**
     * Shared push routine: sends only records that have never been synced
     * (synced_at is null) and marks them synced on success. A sync_logs
     * entry is written on the pushing (this) side for the attempt, and
     * separately on the receiving (timesys-v2) side once the payload has
     * actually been received and processed.
     */
    /**
     * Records are sent in batches rather than all at once: the receiving
     * side checks each record for an existing match individually, so a
     * single request carrying tens of thousands of records (e.g. attendance)
     * would take far longer than any reasonable HTTP timeout and fail
     * without ever getting a chance to sync anything. Batching keeps each
     * request fast and lets already-synced batches survive a later failure.
     */
    private function pushModule(string $modelClass, $query, string $endpoint, string $payloadKey, callable $mapper, int $chunkSize = 500, ?string $syncedFrom = null): JsonResponse
    {
        $targetUrl = config('services.sync.target_url');
        $apiKey    = config('services.sync.api_key');

        if (!$targetUrl || !$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'Sync not configured. Set SYNC_TARGET_URL and SYNC_API_KEY in .env.',
            ], 500);
        }

        if ((clone $query)->count() === 0) {
            return response()->json([
                'success'  => true,
                'synced'   => 0,
                'existing' => 0,
                'skipped'  => 0,
                'message'  => 'Nothing to sync. All records are already synced to timesys-v2.',
                'errors'   => [],
            ]);
        }

        $synced = $existing = $skipped = 0;
        $errors = [];
        $stoppedEarly = false;
        $stopReason   = null;

        // Large backlogs (attendances especially) can contain far more
        // chunks than fit under PHP's max_execution_time (commonly 30s).
        // Rather than let the process die mid-loop with no response body,
        // stop pulling new chunks once this budget is spent and return a
        // normal partial response — the caller is expected to push again
        // to pick up where this call left off.
        //
        // The 20s check only runs between chunks, so a single slow/retried
        // postWithRetry() call (up to 120s per attempt) can still outlast
        // PHP's own max_execution_time and get the whole request fatally
        // killed before that check ever runs again. Raise the hard limit
        // well above the soft deadline so an in-flight chunk always has
        // room to finish (or genuinely fail) before PHP steps in.
        set_time_limit(180);
        $deadline = microtime(true) + 20;

        // Lets the receiver know when it has seen the last chunk of
        // everything that was pending when this call started, so it can
        // write a single aggregated sync_logs row instead of one per chunk.
        $totalPending   = (clone $query)->count();
        $processedSoFar = 0;

        // chunkById pulls one batch at a time from the database instead of
        // loading every pending record into memory up front, which matters
        // for attendances: tens of thousands of rows plus eager-loaded
        // relations can exhaust PHP's memory limit before a single request
        // is even sent.
        $query->chunkById($chunkSize, function ($chunk) use (
            $modelClass, $endpoint, $payloadKey, $mapper, $targetUrl, $apiKey, $deadline, $totalPending, $syncedFrom,
            &$synced, &$existing, &$skipped, &$errors, &$stoppedEarly, &$stopReason, &$processedSoFar
        ) {
            if (microtime(true) >= $deadline) {
                $stoppedEarly = true;
                $stopReason   = 'time_budget';
                return false;
            }

            $processedSoFar += $chunk->count();
            $isFinalChunk    = $processedSoFar >= $totalPending;

            $payload = $chunk->map($mapper)->values()->all();

            try {
                [$response, $attempts] = $this->postWithRetry(
                    $apiKey,
                    rtrim($targetUrl, '/') . $endpoint,
                    [$payloadKey => $payload, 'is_final_chunk' => $isFinalChunk, 'synced_from' => $syncedFrom]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Sync push to timesys-v2 failed for {$payloadKey}", [
                    'endpoint'   => $endpoint,
                    'chunk_size' => $chunk->count(),
                    'exception'  => get_class($e),
                    'message'    => $e->getMessage(),
                ]);
                $errors[]     = "Batch of {$chunk->count()} {$payloadKey}: unable to reach timesys-v2.";
                $stoppedEarly = true;
                $stopReason   = 'error';
                return false;
            }

            if ($response->failed()) {
                $retryNote = $attempts > 1 ? " after {$attempts} attempts" : '';
                $errors[]     = "Batch of {$chunk->count()} {$payloadKey}: timesys-v2 returned an error (HTTP {$response->status()}){$retryNote}.";
                $stoppedEarly = true;
                $stopReason   = 'error';
                return false;
            }

            $result = $response->json();

            // Records the receiver skipped (e.g. a work schedule whose
            // employee hasn't synced yet) still get stamped synced_at here,
            // same as everything else in the chunk — a skip is treated as a
            // resolved attempt rather than something to keep silently
            // retrying on every future push.
            $modelClass::whereIn('id', $chunk->pluck('id'))->update(['synced_at' => now()]);

            $synced   += $result['synced'] ?? 0;
            $existing += $result['existing'] ?? 0;
            $skipped  += $result['skipped'] ?? 0;
            $errors    = array_merge($errors, $result['errors'] ?? []);
        });

        $status = 'success';
        if ($stoppedEarly || !empty($errors)) {
            $status = ($synced > 0 || $existing > 0) ? 'partial' : 'failed';
        }

        $message = "{$synced} synced, {$existing} existing, {$skipped} skipped.";
        if ($stopReason === 'time_budget') {
            $message .= ' Reached the per-request time budget; push again to continue with the remaining records.';
        } elseif ($stoppedEarly) {
            $message .= ' Stopped after a batch failure; remaining records will be retried on the next push.';
        }

        // A large backlog (attendances especially) can take several
        // time-budget-limited requests to fully push (see the frontend's
        // pushModuleUntilDone loop). Only the last one of those requests is
        // "final" from a logging standpoint.
        $isFinal = !($stoppedEarly && $stopReason === 'time_budget');

        $this->recordAggregatedLog(
            $payloadKey, 'push', $isFinal, $status,
            ['synced' => $synced, 'existing' => $existing, 'skipped' => $skipped],
            $errors,
            function (array $totals) use ($stopReason) {
                $msg = "{$totals['synced']} synced, {$totals['existing']} existing, {$totals['skipped']} skipped.";
                if ($stopReason === 'error') {
                    $msg .= ' Stopped after a batch failure; remaining records will be retried on the next push.';
                }
                return $msg;
            }
        );

        return response()->json([
            'success'       => $status !== 'failed',
            'synced'        => $synced,
            'existing'      => $existing,
            'skipped'       => $skipped,
            'message'       => $message,
            'errors'        => $errors,
            'stopped_early' => $stoppedEarly,
            'stop_reason'   => $stopReason,
        ]);
    }

    /**
     * A 500 from timesys-v2 is often transient (e.g. its IIS/FastCGI worker
     * hit an execution time limit under load), so a batch that fails with a
     * server error is worth retrying with a short backoff before giving up.
     * Client errors (4xx) are returned immediately since retrying won't help.
     */
    private function postWithRetry(string $apiKey, string $url, array $body, int $maxAttempts = 3, int $baseDelayMs = 2000): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = Http::withToken($apiKey)->timeout(120)->post($url, $body);
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep($baseDelayMs * 1000 * $attempt);
                continue;
            }

            if ($response->serverError() && $attempt < $maxAttempts) {
                usleep($baseDelayMs * 1000 * $attempt);
                continue;
            }

            return [$response, $attempt];
        }
    }

    public function receiveEmployees(Request $request)
    {
        $employees      = $request->input('employees', []);
        $synced         = 0;
        $skipped        = 0;
        $existing       = 0;
        $errors         = [];
        $seenNos        = [];
        $seenNames      = [];
        $skippedIndexes = [];

        foreach ($employees as $idx => $data) {
            try {
                $fullName = mb_strtolower(trim(($data['first_name'] ?? '') . '|' . ($data['middle_name'] ?? '') . '|' . ($data['last_name'] ?? '')));
                $label    = "{$data['employee_no']} ({$data['first_name']} {$data['last_name']})";

                if (in_array($data['employee_no'], $seenNos, true)) {
                    $skipped++;
                    $skippedIndexes[] = $idx;
                    $errors[] = "Employee {$label}: duplicate employee_no in payload, skipped.";
                    continue;
                }

                if (in_array($fullName, $seenNames, true)) {
                    $skipped++;
                    $skippedIndexes[] = $idx;
                    $errors[] = "Employee {$label}: duplicate full name in payload, skipped.";
                    continue;
                }

                if (Employee::where('employee_no', $data['employee_no'])->exists()) {
                    $existing++;
                    $errors[] = "Employee {$label}: already exists (matched by employee number).";
                    continue;
                }

                if (Employee::where('first_name', $data['first_name'] ?? null)
                    ->where('middle_name', $data['middle_name'] ?? null)
                    ->where('last_name', $data['last_name'] ?? null)
                    ->exists()) {
                    $existing++;
                    $errors[] = "Employee {$label}: already exists (matched by name).";
                    continue;
                }

                $seenNos[]   = $data['employee_no'];
                $seenNames[] = $fullName;

                $officeId = $this->resolveOffice($data['office_name'] ?? null, $data['office_code'] ?? null);

                if (!$officeId) {
                    $skipped++;
                    $skippedIndexes[] = $idx;
                    $errors[] = "Employee {$label}: office name is missing, skipped.";
                    continue;
                }

                Employee::create([
                    'employee_no'          => $data['employee_no'],
                    'first_name'           => $data['first_name'],
                    'middle_name'          => $data['middle_name'] ?? null,
                    'last_name'            => $data['last_name'],
                    'name_ext'             => $data['name_ext'] ?? null,
                    'gender'               => $data['gender'] ?? null,
                    'contact_no'           => $data['contact_no'] ?? null,
                    'job_title'            => $data['job_title'] ?? null,
                    'is_active'            => $data['is_active'] ?? true,
                    'image'                => $data['image'] ?? null,
                    'signature'            => $data['signature'] ?? null,
                    'office_id'            => $officeId,
                    'employment_type_id'   => $this->resolveEmploymentType($data['employment_type_name'] ?? null),
                    'position_id'          => $this->resolvePosition($data['position_name'] ?? null),
                    'office_division_id'   => $this->resolveOfficeDivision($data['office_division_name'] ?? null, $data['office_division_code'] ?? null, $officeId),
                ]);

                $synced++;
            } catch (\Throwable $e) {
                $skipped++;
                $skippedIndexes[] = $idx;
                $errors[] = "Employee " . ($label ?? $data['employee_no'] ?? '?') . ": {$e->getMessage()}";
            }
        }

        return $this->respondReceive('employees', $synced, $existing, $skipped, $errors, 'employee(s)', $request->boolean('is_final_chunk', true), $skippedIndexes);
    }

    public function receiveOffices(Request $request)
    {
        $offices        = $request->input('offices', []);
        $synced         = 0;
        $existing       = 0;
        $skipped        = 0;
        $errors         = [];
        $skippedIndexes = [];

        foreach ($offices as $idx => $data) {
            try {
                if (empty($data['code']) && empty($data['name'])) {
                    $skipped++;
                    $skippedIndexes[] = $idx;
                    $errors[] = 'Office is missing code and name, skipped.';
                    continue;
                }

                $exists = Office::where('code', $data['code'] ?? null)
                    ->orWhere('name', $data['name'] ?? null)
                    ->exists();

                if ($exists) {
                    $existing++;
                    continue;
                }

                Office::create([
                    'code'                => $data['code'] ?? $data['name'],
                    'name'                => $data['name'],
                    'description'         => $data['description'] ?? null,
                    'prefix'              => $data['prefix'] ?? null,
                    'latest_employee_no'  => ($data['prefix'] ?? null) ? 0 : null,
                ]);

                $synced++;
            } catch (\Throwable $e) {
                $skipped++;
                $skippedIndexes[] = $idx;
                $errors[] = "Office {$data['code']}: {$e->getMessage()}";
            }
        }

        return $this->respondReceive('offices', $synced, $existing, $skipped, $errors, 'office(s)', $request->boolean('is_final_chunk', true), $skippedIndexes);
    }

    public function receiveOfficeDivisions(Request $request)
    {
        $divisions      = $request->input('office_divisions', []);
        $synced         = 0;
        $existing       = 0;
        $skipped        = 0;
        $errors         = [];
        $skippedIndexes = [];

        foreach ($divisions as $idx => $data) {
            try {
                $officeId = $this->resolveOffice($data['office_name'] ?? null, $data['office_code'] ?? null);

                if (!$officeId) {
                    $skipped++;
                    $skippedIndexes[] = $idx;
                    $errors[] = "Division {$data['name']}: office is missing, skipped.";
                    continue;
                }

                $exists = OfficeDivision::where('office_id', $officeId)
                    ->where(function ($q) use ($data) {
                        $q->where('code', $data['code'] ?? null)
                          ->orWhere('name', $data['name'] ?? null);
                    })
                    ->exists();

                if ($exists) {
                    $existing++;
                    continue;
                }

                OfficeDivision::create([
                    'code'        => $data['code'] ?? $data['name'],
                    'name'        => $data['name'],
                    'description' => $data['description'] ?? null,
                    'office_id'   => $officeId,
                ]);

                $synced++;
            } catch (\Throwable $e) {
                $skipped++;
                $skippedIndexes[] = $idx;
                $errors[] = "Division {$data['name']}: {$e->getMessage()}";
            }
        }

        return $this->respondReceive('office_divisions', $synced, $existing, $skipped, $errors, 'division(s)', $request->boolean('is_final_chunk', true), $skippedIndexes);
    }

    public function receiveWorkSchedules(Request $request)
    {
        $items          = $request->input('work_schedules', []);
        $synced         = 0;
        $existing       = 0;
        $skipped        = 0;
        $errors         = [];
        $skippedIndexes = [];

        foreach ($items as $idx => $data) {
            try {
                $employeeId = Employee::where('employee_no', $data['employee_no'] ?? null)->value('id');

                if (!$employeeId) {
                    $skipped++;
                    $skippedIndexes[] = $idx;
                    $label    = trim(($data['employee_no'] ?? 'unknown no.') . ' (' . ($data['employee_name'] ?? 'unknown name') . ')');
                    $sourceId = $data['source_id'] ?? '?';
                    $errors[] = "Work schedule (id {$sourceId} on source): employee {$label} not found on central server, skipped.";
                    continue;
                }

                $exists = WorkSchedule::where('employee_id', $employeeId)
                    ->where('schedule_for', $data['schedule_for'] ?? null)
                    ->where('from_date', $data['from_date'] ?? null)
                    ->where('to_date', $data['to_date'] ?? null)
                    ->exists();

                if ($exists) {
                    $existing++;
                    continue;
                }

                WorkSchedule::create([
                    'employee_id'      => $employeeId,
                    'schedule_id'      => $this->resolveSchedule($data['schedule_name'] ?? null),
                    'schedule_type_id' => $this->resolveScheduleType($data['schedule_type_name'] ?? null),
                    'timein_AM'        => $data['timein_AM'] ?? null,
                    'timeout_AM'       => $data['timeout_AM'] ?? null,
                    'timein_PM'        => $data['timein_PM'] ?? null,
                    'timeout_PM'       => $data['timeout_PM'] ?? null,
                    'from_date'        => $data['from_date'] ?? null,
                    'to_date'          => $data['to_date'] ?? null,
                    'is_others'        => $data['is_others'] ?? false,
                    'schedule_for'     => $data['schedule_for'] ?? null,
                    'days'             => $data['days'] ?? [],
                    'no_lunch_gap'     => $data['no_lunch_gap'] ?? false,
                ]);

                $synced++;
            } catch (\Throwable $e) {
                $skipped++;
                $skippedIndexes[] = $idx;
                $errors[] = "Work schedule (id " . ($data['source_id'] ?? '?') . " on source) for {$data['employee_no']}: {$e->getMessage()}";
            }
        }

        return $this->respondReceive('work_schedules', $synced, $existing, $skipped, $errors, 'work schedule(s)', $request->boolean('is_final_chunk', true), $skippedIndexes);
    }

    public function receiveAttendances(Request $request)
    {
        $items          = $request->input('attendances', []);
        $synced         = 0;
        $existing       = 0;
        $skipped        = 0;
        $errors         = [];
        $skippedIndexes = [];

        foreach ($items as $idx => $data) {
            try {
                $employeeId = Employee::where('employee_no', $data['employee_no'] ?? null)->value('id');

                if (!$employeeId) {
                    $skipped++;
                    $skippedIndexes[] = $idx;
                    $errors[] = "Attendance: employee {$data['employee_no']} not found on central server, skipped.";
                    continue;
                }

                $postNo = $data['post_no'] ?? null;

                $exists = Attendance::where('check_time', $data['check_time'] ?? null)
                    ->where('employee_id', $employeeId)
                    ->where('post_no', $postNo)
                    ->exists();

                if ($exists) {
                    $existing++;
                    continue;
                }

                Attendance::create([
                    'check_time'  => $data['check_time'] ?? null,
                    'employee_id' => $employeeId,
                    'serial_no'   => $data['serial_no'] ?? null,
                    'post_no'     => $postNo,
                    'void'        => $data['void'] ?? false,
                ]);

                $synced++;
            } catch (\Throwable $e) {
                $skipped++;
                $skippedIndexes[] = $idx;
                $errors[] = "Attendance for {$data['employee_no']}: {$e->getMessage()}";
            }
        }

        return $this->respondReceive('attendances', $synced, $existing, $skipped, $errors, 'attendance record(s)', $request->boolean('is_final_chunk', true), $skippedIndexes);
    }

    // ── DASHBOARD DATA (public, no login) ───────────────────────────────────

    public function counts(): JsonResponse
    {
        return response()->json([
            'employees'        => Employee::count(),
            'offices'          => Office::count(),
            'office_divisions' => OfficeDivision::count(),
            'work_schedules'   => WorkSchedule::count(),
            'attendances'      => Attendance::count(),
        ]);
    }

    public function logs(Request $request)
    {
        $query = SyncLog::query()->latest();

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        return response()->json($query->paginate(15));
    }

    // ── HELPERS ──────────────────────────────────────────────────────────────

    private function respondReceive(string $module, int $synced, int $existing, int $skipped, array $errors, string $label, bool $isFinal = true, array $skippedIndexes = []): JsonResponse
    {
        // $errors can also carry informational notes (e.g. "employee already
        // exists") that aren't real problems, so status is driven by
        // $skipped rather than by $errors being non-empty.
        $status = 'success';
        if ($skipped > 0) {
            $status = ($synced > 0 || $existing > 0) ? 'partial' : 'failed';
        }

        $message = "{$synced} {$label} synced, {$existing} already exist, {$skipped} skipped.";

        $this->recordAggregatedLog(
            $module, 'receive', $isFinal, $status,
            ['synced' => $synced, 'existing' => $existing, 'skipped' => $skipped],
            $errors,
            fn (array $totals) => "{$totals['synced']} {$label} synced, {$totals['existing']} already exist, {$totals['skipped']} skipped."
        );

        return response()->json([
            'success'         => $status !== 'failed',
            'synced'          => $synced,
            'existing'        => $existing,
            'skipped'         => $skipped,
            'message'         => $message,
            'errors'          => $errors,
            'skipped_indexes' => $skippedIndexes,
        ]);
    }

    private function recordLog(string $module, string $direction, string $status, array $counts, array $errors = [], ?string $message = null): void
    {
        SyncLog::create([
            'module'         => $module,
            'direction'      => $direction,
            'status'         => $status,
            'total_records'  => $counts['total'] ?? 0,
            'synced_count'   => $counts['synced'] ?? 0,
            'existing_count' => $counts['existing'] ?? 0,
            'skipped_count'  => $counts['skipped'] ?? 0,
            'errors'         => $errors,
            'message'        => $message,
        ]);
    }

    /**
     * A push of one module can span several chunked requests — either
     * several chunk-POSTs within a single pushModule() call, or several
     * browser calls once the per-request time budget is hit (see
     * pushModule). Rather than writing a sync_logs row per chunk, this
     * accumulates each chunk's counts/errors in cache and only writes the
     * row once the caller says the whole run is finished ($isFinal), so one
     * logical sync produces exactly one log entry.
     */
    private function recordAggregatedLog(string $module, string $direction, bool $isFinal, string $status, array $counts, array $errors, \Closure $messageBuilder): void
    {
        $key      = "sync_progress:{$direction}:{$module}";
        $progress = Cache::get($key, ['synced' => 0, 'existing' => 0, 'skipped' => 0, 'errors' => []]);
        $progress['synced']   += $counts['synced'] ?? 0;
        $progress['existing'] += $counts['existing'] ?? 0;
        $progress['skipped']  += $counts['skipped'] ?? 0;
        $progress['errors']    = array_merge($progress['errors'], $errors);

        if (!$isFinal) {
            Cache::put($key, $progress, now()->addHour());
            return;
        }

        Cache::forget($key);

        $this->recordLog($module, $direction, $status, [
            'total'    => $progress['synced'] + $progress['existing'] + $progress['skipped'],
            'synced'   => $progress['synced'],
            'existing' => $progress['existing'],
            'skipped'  => $progress['skipped'],
        ], $progress['errors'], $messageBuilder($progress));
    }

    private function resolveOffice(?string $name, ?string $code): ?int
    {
        if (!$name) return null;
        return Office::firstOrCreate(['name' => $name], ['code' => $code ?? $name])->id;
    }

    private function resolveEmploymentType(?string $name): ?int
    {
        if (!$name) return null;
        return EmploymentType::firstOrCreate(['name' => $name])->id;
    }

    private function resolvePosition(?string $name): ?int
    {
        if (!$name) return null;
        return Position::firstOrCreate(['name' => $name])->id;
    }

    private function resolveOfficeDivision(?string $name, ?string $code, int $officeId): ?int
    {
        if (!$name) return null;
        return OfficeDivision::firstOrCreate(
            ['name' => $name, 'office_id' => $officeId],
            ['code' => $code ?? $name]
        )->id;
    }

    private function resolveSchedule(?string $name): ?int
    {
        if (!$name) return null;
        return Schedule::firstOrCreate(['name' => $name])->id;
    }

    private function resolveScheduleType(?string $name): ?int
    {
        if (!$name) return null;
        return ScheduleType::firstOrCreate(['name' => $name])->id;
    }
}
