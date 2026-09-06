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
            $fieldName = $this->getFieldName($field);

            // File fields are handled separately below
            if ($field->field_type === 'file') {
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
                case 'file':
                    $rule[] = 'file';
                    $rule[] = 'max:5120';
                    $rule[] = 'mimes:pdf,docx,pptx,zip';
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
        $rules['files.*'] = 'nullable|file|max:5120|mimes:pdf,docx,pptx,zip';

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

        // Handle files
        if ($request->hasFile('files')) {
            $directory = "submissions/{$form->id}/{$submission->id}";
            
            foreach ($request->file('files') as $file) {
                $originalName = $file->getClientOriginalName();
                $storedName = $form->is_anonymous 
                    ? $submission->anonymous_code . '_' . $originalName
                    : $originalName;
                
                $path = $file->storeAs($directory, $storedName, 'public');

                SubmissionFile::create([
                    'submission_id' => $submission->id,
                    'original_name' => $originalName,
                    'stored_name' => $storedName,
                    'file_path' => $path,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
            }
        }

        return redirect()->route('submission.recap', ['token' => $token, 'submission' => $submission->id]);
    }

    /**
     * Get the input name for a form field
     */
    private function getFieldName($field): string
    {
        // File fields use the 'files' input name
        if ($field->field_type === 'file') {
            return 'files';
        }

        return match(strtolower($field->field_label)) {
            'nom complet', 'nom', 'name' => 'student_name',
            'email', 'adresse email' => 'student_email',
            'téléphone', 'telephone', 'tel', 'phone' => 'student_phone',
            'filière', 'filiere', 'major', 'spécialité' => 'student_major',
            default => 'student_' . strtolower(str_replace(' ', '_', $field->field_label)),
        };
    }

    /**
     * Get the display value for a field from the request
     */
    private function getFieldValue(Request $request, $field): ?string
    {
        $fieldName = $this->getFieldName($field);
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

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            foreach ($submissions as $submission) {
                $folderName = $form->is_anonymous 
                    ? $submission->anonymous_code 
                    : "{$submission->student_name}_{$submission->student_email}";
                
                $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $folderName);
                
                foreach ($submission->files as $file) {
                    $filePath = Storage::disk('public')->path($file->file_path);
                    if (file_exists($filePath)) {
                        $zip->addFile($filePath, "{$folderName}/{$file->original_name}");
                    }
                }
            }
            $zip->close();
        }

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }
}
