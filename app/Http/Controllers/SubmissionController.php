<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Support\SubmissionFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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

        /*
         | Libellés lisibles dans les messages d'erreur : sans eux, un
         | étudiant verrait « le champ file_9 » au lieu du vrai intitulé
         | du champ (« Rapport ENG2XX »).
         */
        $attributes = [];

        foreach ($form->fields as $field) {
            $fieldName = $field->getFieldName();
            $attributes[$fieldName] = $field->field_label;

            if ($field->field_type === 'file') {
                $attributes[$fieldName.'.*'] = $field->field_label;
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
            $attributes['student_email'] = 'adresse email';
        }

        $validated = Validator::make($request->all(), $rules, [], $attributes)->validate();

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

    /**
     * Soumissions d'un formulaire, éventuellement filtrées par période, plage
     * de dates et statut.
     *
     * Même lecture d'URL que le tableau de bord (SubmissionFilters) : le filtre
     * « formulaire » est simplement omis, la page étant déjà limitée au sien.
     */
    public function adminIndex(Request $request, Form $form)
    {
        $filters = SubmissionFilters::fromRequest($request, withForm: false);

        $submissions = $filters->apply($form->submissions()->with('files'))
            ->orderByDesc('created_at')
            ->get();

        return view('admin.submissions.index', [
            'form' => $form,
            'submissions' => $submissions,
            'filters' => [
                'period' => $filters->period,
                'from' => $filters->from,
                'to' => $filters->to,
                'status' => $filters->status,
            ],
            'isFiltered' => $filters->isActive(),
            // Le total non filtré sert de repère : « 1 sur 3 soumissions ».
            'totalCount' => $form->submissions()->count(),
            // Le ZIP suit les mêmes filtres que la liste : le lien embarque
            // donc la sélection courante, sinon l'administrateur croirait
            // télécharger ce qu'il vient d'isoler.
            'bulkUrl' => route('admin.submissions.bulk', array_merge(
                ['form' => $form->id],
                $this->filterQuery($filters)
            )),
        ]);
    }

    /**
     * Filtres à transmettre à une URL (téléchargement), sans paramètre inutile.
     *
     * @return array<string, scalar>
     */
    private function filterQuery(SubmissionFilters $filters): array
    {
        return array_filter([
            'period' => $filters->period !== 'all' ? $filters->period : null,
            'from' => $filters->from,
            'to' => $filters->to,
            'status' => $filters->status,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function adminShow(Form $form, Submission $submission)
    {
        $this->assertSubmissionBelongsToForm($form, $submission);
        $submission->load('files');

        return view('admin.submissions.show', compact('form', 'submission'));
    }

    public function adminEdit(Form $form, Submission $submission)
    {
        $this->assertSubmissionBelongsToForm($form, $submission);
        $submission->load('files');

        return view('admin.submissions.edit', compact('form', 'submission'));
    }

    /**
     * Update the editable fields of a submission. IP address and creation
     * timestamp are deliberately immutable — they carry the audit trail.
     */
    public function adminUpdate(Request $request, Form $form, Submission $submission)
    {
        $this->assertSubmissionBelongsToForm($form, $submission);

        $validated = $request->validate([
            'student_name' => ['nullable', 'string', 'max:255'],
            'student_email' => ['nullable', 'email', 'max:255'],
            'student_phone' => ['nullable', 'string', 'max:20'],
            'student_major' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['pending', 'validated'])],
        ], [
            'student_email.email' => 'L\'adresse email saisie n\'est pas valide.',
            'status.required' => 'Le statut est obligatoire.',
            'status.in' => 'Le statut sélectionné est invalide.',
        ]);

        $validated['student_email'] = $validated['student_email'] !== null
            ? Str::lower(trim($validated['student_email']))
            : null;

        // Another submission of the same form already uses this email?
        if ($validated['student_email'] !== null
            && $form->submissions()
                ->whereKeyNot($submission->id)
                ->where('student_email', $validated['student_email'])
                ->exists()) {
            return back()
                ->withErrors(['student_email' => 'Une autre soumission de ce formulaire utilise déjà cette adresse email.'])
                ->withInput();
        }

        $submission->update($validated);

        return redirect()
            ->route('admin.submissions.show', ['form' => $form, 'submission' => $submission])
            ->with('success', 'Soumission mise à jour avec succès !');
    }

    /**
     * Delete a submission together with its stored files (rows cascade in
     * the database; the physical files must be removed explicitly).
     */
    public function adminDestroy(Form $form, Submission $submission)
    {
        $this->assertSubmissionBelongsToForm($form, $submission);

        $submission->load('files');

        foreach ($submission->files as $file) {
            Storage::disk('local')->delete($file->file_path);
        }

        $submission->delete();

        return redirect()
            ->route('admin.submissions.index', $form)
            ->with('success', 'Soumission supprimée avec succès !');
    }

    /**
     * Attach an extra file to an existing submission (student forgot a
     * document). Same extension and size rules as the public upload flow.
     */
    public function adminAddFile(Request $request, Form $form, Submission $submission)
    {
        $this->assertSubmissionBelongsToForm($form, $submission);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_FILE_SIZE_KB,
                'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ], [
            'file.required' => 'Aucun fichier sélectionné.',
            'file.max' => 'Le fichier ne peut pas dépasser 5 Mo.',
            'file.mimes' => 'Le fichier doit être de type : pdf, docx, pptx ou zip.',
        ]);

        $this->storeSubmissionFile($form, $submission, $request->file('file'));

        return back()->with('success', 'Fichier ajouté avec succès !');
    }

    /**
     * Delete one attachment and its stored file.
     */
    public function adminDestroyFile(Form $form, Submission $submission, SubmissionFile $file)
    {
        $this->assertFileBelongsToAuthorizedSubmission($file);
        $this->assertSubmissionBelongsToForm($form, $submission);
        $this->assertFileBelongsToSubmission($submission, $file);

        Storage::disk('local')->delete($file->file_path);
        $file->delete();

        return back()->with('success', 'Fichier supprimé avec succès !');
    }

    /**
     * Replace an attachment (student uploaded the wrong document): the old
     * stored file is removed, the new one takes its place, the row keeps its
     * identity (created_at, field_label).
     */
    public function adminReplaceFile(Request $request, Form $form, Submission $submission, SubmissionFile $file)
    {
        $this->assertFileBelongsToAuthorizedSubmission($file);
        $this->assertSubmissionBelongsToForm($form, $submission);
        $this->assertFileBelongsToSubmission($submission, $file);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_FILE_SIZE_KB,
                'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ], [
            'file.required' => 'Aucun fichier sélectionné.',
            'file.max' => 'Le fichier ne peut pas dépasser 5 Mo.',
            'file.mimes' => 'Le fichier doit être de type : pdf, docx, pptx ou zip.',
        ]);

        Storage::disk('local')->delete($file->file_path);

        $this->storeSubmissionFile($form, $submission, $request->file('file'), $file);

        return back()->with('success', 'Fichier remplacé avec succès !');
    }

    /**
     * Shared persistence for admin-uploaded attachments. When $file is
     * provided the existing row is updated in place (replacement); otherwise
     * a new row is created (addition).
     */
    private function storeSubmissionFile(Form $form, Submission $submission, UploadedFile $upload, ?SubmissionFile $file = null): void
    {
        // Store under the submission's own directory, same as public uploads.
        $directory = "submissions/{$form->id}/{$submission->id}";
        $extension = strtolower($upload->getClientOriginalExtension());
        $storedName = Str::uuid()->toString().'.'.$extension;
        $path = $upload->storeAs($directory, $storedName, 'local');

        $attributes = [
            'field_label' => $file->field_label ?? 'Ajout admin',
            'original_name' => Str::limit($upload->getClientOriginalName(), 255, ''),
            'stored_name' => $storedName,
            'file_path' => $path,
            'file_size' => $upload->getSize(),
            'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
        ];

        if ($file !== null) {
            $file->update($attributes);
        } else {
            $file = new SubmissionFile($attributes);
            $file->submission_id = $submission->id;
            $file->save();
        }
    }

    private function assertFileBelongsToSubmission(Submission $submission, SubmissionFile $file): void
    {
        abort_unless((int) $file->submission_id === (int) $submission->id, 404);
    }

    public function downloadFile(SubmissionFile $file)
    {
        $this->assertFileBelongsToAuthorizedSubmission($file);
        $path = $this->filePath($file->file_path);

        abort_unless($path !== null, 404);

        return response()->download($path, basename($file->original_name));
    }

    /**
     * Téléchargement ZIP des soumissions, filtré exactement comme la liste.
     *
     * Un administrateur qui isole « les dépôts en attente de cette semaine »
     * s'attend à ne récupérer que ceux-là : télécharger tout le formulaire
     * contredirait le filtre qu'il vient d'appliquer.
     */
    public function downloadBulk(Request $request, Form $form)
    {
        $filters = SubmissionFilters::fromRequest($request, withForm: false);

        $submissions = $filters->apply($form->submissions()->with('files'))
            ->orderByDesc('created_at')
            ->get();

        // Une archive vide n'apprend rien : on le dit plutôt que de livrer un
        // ZIP sans contenu.
        if ($submissions->isEmpty()) {
            return back()->with(
                'error',
                'Aucune soumission ne correspond aux filtres actifs : il n\'y a rien à télécharger.'
            );
        }

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
        // ZipArchive n'écrit aucun fichier quand il n'y a rien à archiver :
        // response()->download() lèverait alors « The file does not exist »,
        // soit une erreur 500 pour un simple téléchargement sans contenu. Le
        // cas se produit quand les pièces jointes sont absentes du disque, ou
        // quand les filtres ne retiennent que des soumissions sans fichier.
        if ($entries === []) {
            return back()->with(
                'error',
                'Aucun fichier à inclure : les pièces jointes de cette sélection sont introuvables sur le serveur.'
            );
        }

        if ($this->hasNativeZip() && ! config('app.zip_stream_fallback', false)) {
            $zipPath = storage_path('app/private/'.Str::uuid()->toString().'.zip');
            $zip = new \ZipArchive;

            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return back()->with('error', 'Impossible de créer l\'archive ZIP.');
            }

            foreach ($entries as [$archivePath, $filePath]) {
                $zip->addFile($filePath, $archivePath);
            }

            if (! $zip->close() || ! is_file($zipPath)) {
                return back()->with('error', 'Impossible de finaliser l\'archive ZIP.');
            }

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
