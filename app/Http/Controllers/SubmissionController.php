<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use ZipStream\ZipStream;

class SubmissionController extends Controller
{
    private const MAX_FILES_PER_FIELD = 10;

    private const MAX_FILE_SIZE_KB = 5120;

    private const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'pptx', 'zip'];

    public function showForm(string $token)
    {
        $form = Form::where('token', $token)->with('fields')->firstOrFail();

        if (! $form->isOpen()) {
            if ($form->status !== 'active') {
                return view('student.closed', ['form' => $form, 'reason' => 'Ce formulaire est actuellement inactif.']);
            }

            if ($form->close_date && now()->gt($form->close_date)) {
                return view('student.closed', ['form' => $form, 'reason' => 'La date limite de soumission est dépassée.']);
            }

            if ($form->max_submissions && $form->submissions()->count() >= $form->max_submissions) {
                return view('student.closed', ['form' => $form, 'reason' => 'Le nombre maximum de soumissions a été atteint.']);
            }

            return view('student.closed', ['form' => $form, 'reason' => 'Ce formulaire n\'est pas encore ouvert.']);
        }

        return view('student.form', compact('form'));
    }

    public function submit(Request $request, string $token)
    {
        $form = Form::where('token', $token)->with('fields')->firstOrFail();

        if (! $form->isOpen()) {
            return back()->with('error', 'Ce formulaire n\'est plus ouvert.');
        }

        $rules = [];
        $fieldNames = [];

        foreach ($form->fields as $field) {
            $fieldName = $field->getFieldName();

            if ($field->field_type === 'file') {
                $rules[$fieldName] = [$field->required ? 'required' : 'nullable', 'array', 'max:'.self::MAX_FILES_PER_FIELD];
                $rules[$fieldName.'.*'] = [
                    'file',
                    'max:'.self::MAX_FILE_SIZE_KB,
                    'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
                    'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
                ];

                continue;
            }

            $fieldNames[] = $fieldName;
            $rule = [$field->required ? 'required' : 'nullable'];

            switch ($field->field_type) {
                case 'email':
                    $rule[] = 'email';
                    $rule[] = 'max:255';
                    break;
                case 'tel':
                    $rule[] = 'string';
                    $rule[] = 'max:20';
                    break;
                case 'select':
                    $rule[] = 'string';
                    $options = $field->getOptionsList();
                    if ($options !== []) {
                        $rule[] = Rule::in($options);
                    }
                    break;
                case 'checkbox':
                    $rule[] = 'boolean';
                    break;
                default:
                    $rule[] = 'string';
                    $rule[] = 'max:255';
                    break;
            }

            $rules[$fieldName] = $rule;
        }

        if (! in_array('student_email', $fieldNames, true)) {
            $rules['student_email'] = ['required', 'email', 'max:255'];
        }

        $validated = $request->validate($rules);

        $uploadedFileCount = 0;
        foreach ($form->fields as $field) {
            if ($field->field_type !== 'file') {
                continue;
            }

            $uploadedFileCount += count((array) $request->file($field->getFieldName(), []));
        }
        abort_if($uploadedFileCount > 20, 422, 'Trop de fichiers envoyés.');

        $uploadedBytes = 0;
        foreach ($request->allFiles() as $files) {
            foreach ((array) $files as $file) {
                if ($file instanceof UploadedFile) {
                    $uploadedBytes += (int) $file->getSize();
                }
            }
        }
        abort_if($uploadedBytes > 100 * 1024 * 1024, 422, 'La taille totale des fichiers dépasse 100 Mo.');

        $studentEmail = Str::lower(trim($validated['student_email'] ?? ''));

        if (Submission::where('form_id', $form->id)->where('student_email', $studentEmail)->exists()) {
            return back()->withInput()->with('error', 'Vous avez déjà soumis pour ce formulaire avec cette adresse email.');
        }

        $submission = DB::transaction(function () use ($request, $form, $validated, $studentEmail): Submission {
            $submission = Submission::create([
                'form_id' => $form->id,
                'student_name' => $validated['student_name'] ?? null,
                'student_email' => $studentEmail,
                'student_phone' => $validated['student_phone'] ?? null,
                'student_major' => $validated['student_major'] ?? null,
                'anonymous_code' => $form->is_anonymous ? $this->generateAnonymousCode() : null,
                'receipt_token' => Str::random(64),
                'status' => 'validated',
                'ip_address' => $request->ip(),
            ]);

            $directory = "submissions/{$form->id}/{$submission->id}";

            foreach ($form->fields as $field) {
                if ($field->field_type !== 'file') {
                    continue;
                }

                $files = $request->file($field->getFieldName(), []);
                $files = is_array($files) ? $files : ($files ? [$files] : []);

                foreach ($files as $file) {
                    $extension = strtolower($file->getClientOriginalExtension());
                    $storedName = Str::uuid()->toString().'.'.$extension;
                    $path = $file->storeAs($directory, $storedName, 'local');

                    SubmissionFile::create([
                        'submission_id' => $submission->id,
                        'field_label' => $field->field_label,
                        'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
                        'stored_name' => $storedName,
                        'file_path' => $path,
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    ]);
                }
            }

            return $submission;
        });

        return redirect()->route('submission.recap', ['token' => $token, 'receiptToken' => $submission->receipt_token]);
    }

    private function generateAnonymousCode(): string
    {
        do {
            $code = 'ANON-'.strtoupper(bin2hex(random_bytes(3)));
        } while (Submission::where('anonymous_code', $code)->exists());

        return $code;
    }

    public function recap(string $token, string $receiptToken)
    {
        $form = Form::where('token', $token)->firstOrFail();
        $submission = $this->submissionForReceipt($form, $receiptToken);

        return view('student.recap', compact('form', 'submission'));
    }

    public function downloadRecap(string $token, string $receiptToken)
    {
        $form = Form::where('token', $token)->firstOrFail();
        $submission = $this->submissionForReceipt($form, $receiptToken);

        $pdf = Pdf::loadView('student.recap-pdf', compact('form', 'submission'));

        return $pdf->download("recapitulatif_{$submission->id}.pdf");
    }

    public function adminIndex(Form $form)
    {
        $submissions = $form->submissions()->with('files')->orderByDesc('created_at')->get();

        return view('admin.submissions.index', compact('form', 'submissions'));
    }

    public function adminShow(Form $form, Submission $submission)
    {
        $this->assertSubmissionBelongsToForm($form, $submission);
        $submission->load('files');

        return view('admin.submissions.show', compact('form', 'submission'));
    }

    public function downloadFile(SubmissionFile $file)
    {
        $this->assertFileBelongsToAuthorizedSubmission($file);
        $path = $this->filePath($file->file_path);

        abort_unless($path !== null, 404);

        return response()->download($path, basename($file->original_name));
    }

    public function downloadBulk(Form $form)
    {
        $submissions = $form->submissions()->with('files')->get();
        $zipFileName = 'soumissions_'.$form->id.'_'.now()->format('Y-m-d_H-i-s').'_'.Str::random(8).'.zip';

        $entries = [];
        foreach ($submissions as $submission) {
            $entries = array_merge(
                $entries,
                $this->submissionZipEntries($submission, $this->submissionFolderName($form, $submission))
            );
        }

        return $this->zipDownload($zipFileName, $entries);
    }

    public function downloadSubmission(Form $form, Submission $submission)
    {
        $this->assertSubmissionBelongsToForm($form, $submission);
        $submission->load('files');

        $zipFileName = "soumission_{$submission->id}_".Str::random(8).'.zip';
        $entries = $this->submissionZipEntries($submission, $this->submissionFolderName($form, $submission));

        return $this->zipDownload($zipFileName, $entries);
    }

    private function submissionForReceipt(Form $form, string $receiptToken): Submission
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $receiptToken) === 1, 404);

        return $form->submissions()
            ->where('receipt_token', $receiptToken)
            ->with(['files', 'form.fields'])
            ->firstOrFail();
    }

    private function assertSubmissionBelongsToForm(Form $form, Submission $submission): void
    {
        abort_unless((int) $submission->form_id === (int) $form->id, 404);
    }

    private function assertFileBelongsToAuthorizedSubmission(SubmissionFile $file): void
    {
        $file->loadMissing('submission.form');
        abort_unless(
            $file->submission
                && $file->submission->form
                && $file->submission->form->submissions()->whereKey($file->submission->id)->exists(),
            404
        );
    }

    private function submissionFolderName(Form $form, Submission $submission): string
    {
        $folderName = $form->is_anonymous && $submission->anonymous_code
            ? $submission->anonymous_code
            : $this->nameToFolderName($submission->student_name);

        $folderName = $folderName ?: 'soumission_'.$submission->id;
        $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($folderName));
        $folderName = preg_replace('/_+/', '_', $folderName);

        return trim($folderName, '_') ?: 'soumission_'.$submission->id;
    }

    /**
     * Build the list of files to archive as [path inside the archive,
     * absolute path on disk]. Shared by both ZIP backends.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function submissionZipEntries(Submission $submission, string $folderName): array
    {
        $entries = [];
        $usedNames = [];

        foreach ($submission->files as $file) {
            $filePath = $this->filePath($file->file_path);
            if ($filePath === null) {
                continue;
            }

            $destDir = $folderName;
            if (! empty($file->field_label)) {
                $label = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($file->field_label));
                $label = trim(preg_replace('/_+/', '_', $label), '_');
                if ($label !== '') {
                    $destDir .= '/'.$label;
                }
            }

            $destName = basename($file->original_name ?: $filePath);
            $base = pathinfo($destName, PATHINFO_FILENAME);
            $ext = pathinfo($destName, PATHINFO_EXTENSION);
            $uniqueName = $destName;
            $suffix = 1;
            while (isset($usedNames[$destDir][$uniqueName])) {
                $uniqueName = $base.'_'.(++$suffix).($ext !== '' ? '.'.$ext : '');
            }
            $usedNames[$destDir][$uniqueName] = true;

            $entries[] = ["{$destDir}/{$uniqueName}", $filePath];
        }

        return $entries;
    }

    /**
     * Return a download response for the given archive entries.
     *
     * Uses the native zip extension when available and transparently falls
     * back to the pure-PHP ZipStream library otherwise (some shared hosts
     * ship PHP without ext-zip).
     *
     * @param  array<int, array{0: string, 1: string}>  $entries
     */
    private function zipDownload(string $downloadName, array $entries)
    {
        if ($this->hasNativeZip() && ! config('app.zip_stream_fallback', false)) {
            $zipPath = storage_path('app/private/'.Str::uuid()->toString().'.zip');
            $zip = new \ZipArchive;

            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return back()->with('error', 'Impossible de créer l\'archive ZIP.');
            }

            foreach ($entries as [$archivePath, $filePath]) {
                $zip->addFile($filePath, $archivePath);
            }
            $zip->close();

            return response()->download($zipPath, $downloadName)->deleteFileAfterSend(true);
        }

        return response()->streamDownload(function () use ($entries): void {
            $zip = new ZipStream(outputStream: fopen('php://output', 'wb'), sendHttpHeaders: false);

            foreach ($entries as [$archivePath, $filePath]) {
                $zip->addFileFromPath(fileName: $archivePath, path: $filePath);
            }

            $zip->finish();
        }, $downloadName, ['Content-Type' => 'application/zip']);
    }

    private function hasNativeZip(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * New uploads are private. The public fallback keeps files uploaded by
     * older releases downloadable while they are being migrated safely.
     */
    private function filePath(string $path): ?string
    {
        $local = Storage::disk('local');
        if ($local->exists($path)) {
            return $local->path($path);
        }

        $legacy = Storage::disk('public');
        if ($legacy->exists($path)) {
            return $legacy->path($path);
        }

        return null;
    }

    private function nameToFolderName(?string $fullName): string
    {
        $parts = preg_split('/\s+/', trim((string) $fullName));
        $parts = array_values(array_filter($parts, static fn ($part) => $part !== ''));

        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        $nom = array_pop($parts);

        return $nom.'_'.implode('_', $parts);
    }
}
