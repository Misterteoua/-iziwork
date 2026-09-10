<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class SubmissionController extends Controller
{
    public function showForm($token)
    {
        $form = Form::where('token', $token)->with('fields')->firstOrFail();

        if (!$form->isOpen()) {
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

    public function submit(Request $request, $token)
    {
        $form = Form::where('token', $token)->with('fields')->firstOrFail();

        if (!$form->isOpen()) {
            return back()->with('error', 'Ce formulaire n\'est plus ouvert.');
        }

        // Build validation rules dynamically from form fields
        $rules = [];
        $fieldNames = [];

        foreach ($form->fields as $field) {
            $fieldName = $field->getFieldName();

            // File fields are validated individually so each one can be
            // associated back to its field (RAPPORT, SYNTHESE, ...).
            if ($field->field_type === 'file') {
                $rules[$fieldName] = $field->required ? 'required|array|min:1' : 'nullable|array';
                $rules[$fieldName . '.*'] = 'file|max:5120|mimes:pdf,docx,pptx,zip';
                continue;
            }

            $fieldNames[] = $fieldName;

            $rule = [];
            if ($field->required) {
                $rule[] = 'required';
            } else {
                $rule[] = 'nullable';
            }

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
                    if ($field->options && count($field->options) > 0) {
                        $rule[] = 'in:' . implode(',', $field->options);
                    }
                    break;
                case 'checkbox':
                    $rule[] = 'boolean';
                    break;
                default: // text
                    $rule[] = 'string';
                    $rule[] = 'max:255';
                    break;
            }

            $rules[$fieldName] = implode('|', $rule);
        }

        // Always validate email for duplicate check
        if (!in_array('student_email', $fieldNames)) {
            $rules['student_email'] = 'required|email|max:255';
        }

        $validated = $request->validate($rules);

        // Get student email for duplicate check
        $studentEmail = $validated['student_email'] ?? null;

        // Check for duplicate email
        $existingSubmission = Submission::where('form_id', $form->id)
            ->where('student_email', $studentEmail)
            ->first();

        if ($existingSubmission) {
            return back()->withInput()
                ->with('error', 'Vous avez déjà soumis pour ce formulaire avec cette adresse email.');
        }

        // Create submission
        $submission = Submission::create([
            'form_id' => $form->id,
            'student_name' => $validated['student_name'] ?? null,
            'student_email' => $studentEmail,
            'student_phone' => $validated['student_phone'] ?? null,
            'student_major' => $validated['student_major'] ?? null,
            'anonymous_code' => $form->is_anonymous ? $this->generateAnonymousCode() : null,
            'status' => 'validated',
            'ip_address' => $request->ip(),
        ]);

        // Handle files, one input per file field so each upload is associated
        // with the field (RAPPORT, SYNTHESE, ...) it was uploaded into.
        $directory = "submissions/{$form->id}/{$submission->id}";

        foreach ($form->fields as $field) {
            if ($field->field_type !== 'file') {
                continue;
            }

            $files = $request->file($field->getFieldName());
            $files = is_array($files) ? $files : (is_null($files) ? [] : [$files]);

            foreach ($files as $file) {
                $originalName = $file->getClientOriginalName();
                $storedName = $form->is_anonymous
                    ? $submission->anonymous_code . '_' . $originalName
                    : $originalName;

                // When several uploaded files share the same name, keep each
                // one instead of overwriting the previous file on disk.
                $base = pathinfo($storedName, PATHINFO_FILENAME);
                $ext = pathinfo($storedName, PATHINFO_EXTENSION);
                $uniqueStoredName = $storedName;
                $suffix = 1;
                while (Storage::disk('public')->exists("{$directory}/{$uniqueStoredName}")) {
                    $uniqueStoredName = $base . '_' . (++$suffix) . ($ext !== '' ? '.' . $ext : '');
                }

                $path = $file->storeAs($directory, $uniqueStoredName, 'public');

                SubmissionFile::create([
                    'submission_id' => $submission->id,
                    'field_label' => $field->field_label,
                    'original_name' => $originalName,
                    'stored_name' => $uniqueStoredName,
                    'file_path' => $path,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
            }
        }

        return redirect()->route('submission.recap', ['token' => $token, 'submission' => $submission->id]);
    }

    /**
     * Get the display value for a field from the request
     */
    private function getFieldValue(Request $request, $field): ?string
    {
        $fieldName = $field->getFieldName();
        $value = $request->input($fieldName);

        if ($field->field_type === 'checkbox') {
            return $value ? 'Oui' : 'Non';
        }

        return $value;
    }

    private function generateAnonymousCode(): string
    {
        $code = 'ANON-' . strtoupper(bin2hex(random_bytes(3)));
        
        while (Submission::where('anonymous_code', $code)->exists()) {
            $code = 'ANON-' . strtoupper(bin2hex(random_bytes(3)));
        }
        
        return $code;
    }

    public function recap($token, $submissionId)
    {
        $form = Form::where('token', $token)->firstOrFail();
        $submission = Submission::where('form_id', $form->id)
            ->where('id', $submissionId)
            ->with('files')
            ->firstOrFail();

        return view('student.recap', compact('form', 'submission'));
    }

    public function downloadRecap($token, $submissionId)
    {
        $form = Form::where('token', $token)->firstOrFail();
        $submission = Submission::where('form_id', $form->id)
            ->where('id', $submissionId)
            ->with('files')
            ->firstOrFail();

        $pdf = Pdf::loadView('student.recap-pdf', compact('form', 'submission'));

        $filename = "recapitulatif_{$submission->id}.pdf";
        
        return $pdf->download($filename);
    }

    // Admin methods
    public function adminIndex(Form $form)
    {
        $submissions = $form->submissions()->with('files')->orderByDesc('created_at')->get();
        
        return view('admin.submissions.index', compact('form', 'submissions'));
    }

    public function adminShow(Form $form, Submission $submission)
    {
        $submission->load('files');
        
        return view('admin.submissions.show', compact('form', 'submission'));
    }

    public function downloadFile(SubmissionFile $file)
    {
        $path = Storage::disk('public')->path($file->file_path);
        
        return response()->download($path, $file->original_name);
    }

    public function downloadBulk(Form $form)
    {
        $submissions = $form->submissions()->with('files')->get();

        $zip = new \ZipArchive();
        $zipFileName = "soumissions_{$form->id}_" . now()->format('Y-m-d_H-i-s') . ".zip";
        $zipPath = storage_path("app/{$zipFileName}");

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return back()->with('error', 'Impossible de créer l\'archive ZIP.');
        }

        foreach ($submissions as $submission) {
            $this->addSubmissionFilesToZip($zip, $submission, $this->submissionFolderName($form, $submission));
        }
        $zip->close();

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    public function downloadSubmission(Form $form, Submission $submission)
    {
        $submission->load('files');

        $zip = new \ZipArchive();
        $zipFileName = "soumission_{$submission->id}.zip";
        $zipPath = storage_path("app/{$zipFileName}");

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return back()->with('error', 'Impossible de créer l\'archive ZIP.');
        }

        $this->addSubmissionFilesToZip($zip, $submission, $this->submissionFolderName($form, $submission));
        $zip->close();

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    /**
     * Folder name for a submission inside a ZIP archive: the student's name
     * ordered "nom_prenom" (family name first), or the anonymous code for
     * anonymous submissions.
     */
    private function submissionFolderName(Form $form, Submission $submission): string
    {
        $folderName = $form->is_anonymous && $submission->anonymous_code
            ? $submission->anonymous_code
            : $this->nameToFolderName($submission->student_name);

        if (empty($folderName)) {
            $folderName = $submission->student_email;
        }

        $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($folderName));
        $folderName = preg_replace('/_+/', '_', $folderName);

        return trim($folderName, '_');
    }

    /**
     * Add every file of a submission to the ZIP archive, each with a unique
     * name inside the given folder (same original names get a _2, _3...
     * suffix, so no upload is ever dropped).
     */
    private function addSubmissionFilesToZip(\ZipArchive $zip, Submission $submission, string $folderName): void
    {
        $usedNames = [];
        foreach ($submission->files as $file) {
            $filePath = Storage::disk('public')->path($file->file_path);
            if (!file_exists($filePath)) {
                continue;
            }

            // Group the file under its form field (RAPPORT, SYNTHESE, ...)
            // when known; files without a field go straight into the folder.
            $destDir = $folderName;
            if (!empty($file->field_label)) {
                $label = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($file->field_label));
                $label = preg_replace('/_+/', '_', $label);
                $label = trim($label, '_');
                if ($label !== '') {
                    $destDir .= '/' . $label;
                }
            }

            $destName = $file->original_name ?: basename($filePath);
            $base = pathinfo($destName, PATHINFO_FILENAME);
            $ext = pathinfo($destName, PATHINFO_EXTENSION);
            $uniqueName = $destName;
            $suffix = 1;
            while (isset($usedNames[$destDir][$uniqueName])) {
                $uniqueName = $base . '_' . (++$suffix) . ($ext !== '' ? '.' . $ext : '');
            }
            $usedNames[$destDir][$uniqueName] = true;

            $zip->addFile($filePath, "{$destDir}/{$uniqueName}");
        }
    }

    /**
     * Turn a student's full name into a folder name ordered "nom_prenom":
     * the last word is treated as the family name, the remaining words as
     * the given names. Single-word names are kept as-is.
     */
    private function nameToFolderName(?string $fullName): string
    {
        $parts = preg_split('/\s+/', trim((string) $fullName));
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        $nom = array_pop($parts);

        return $nom . '_' . implode('_', $parts);
    }
}
