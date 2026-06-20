<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Document;

use App\Models\ProcedureDocument;
use App\Orchid\Layouts\Document\DocumentEditLayout;
use App\Services\Documents\DocumentAdminService;
use App\Services\Documents\DocumentFragmentReferenceException;
use App\Services\Documents\DocumentScopeValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ProcedureDocumentEditScreen extends Screen
{
    /**
     * @var ProcedureDocument
     */
    public $document;

    /**
     * @return array<string, ProcedureDocument>
     */
    public function query(ProcedureDocument $procedureDocument): iterable
    {
        return [
            'document' => $procedureDocument->load('fragmentReferences.fragment'),
        ];
    }

    public function name(): ?string
    {
        return $this->document->exists ? 'Edit Procedure' : 'Create Procedure';
    }

    public function description(): ?string
    {
        return 'Edit Markdown source and review the saved rendered procedure below.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return ['platform.procedure-documents'];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Cancel'))
                ->icon('bs.x-circle')
                ->route('platform.procedure-documents'),

            Button::make(__('Save Procedure'))
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(new DocumentEditLayout(ProcedureDocument::class))
                ->title(__('Procedure'))
                ->description(__('Procedure source remains Markdown with explicit fragment references.')),
            Layout::block(Layout::view('orchid.documents.fragment-references'))
                ->title(__('Fragment references')),
            Layout::block(Layout::view('orchid.documents.preview'))
                ->title(__('Rendered preview')),
        ];
    }

    public function save(
        Request $request,
        ProcedureDocument $procedureDocument,
        DocumentAdminService $documents,
        DocumentScopeValidator $scopes,
    ): RedirectResponse {
        $validated = $this->validateDocument($request, $procedureDocument, $scopes);

        try {
            $documents->save(
                $procedureDocument,
                $validated,
                $request->user(),
                $request->input('document.reason'),
            );
        } catch (DocumentFragmentReferenceException $exception) {
            throw ValidationException::withMessages([
                'document.markdown_source' => $exception->getMessage(),
            ]);
        }

        Toast::info(__('Procedure was saved.'));

        return redirect()->route('platform.procedure-documents');
    }

    /**
     * @return array<string, string>
     */
    private function validateDocument(
        Request $request,
        ProcedureDocument $procedureDocument,
        DocumentScopeValidator $scopes,
    ): array {
        $validator = Validator::make($request->all(), [
            'document.organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'document.scope_type' => ['required', 'string', Rule::in(ProcedureDocument::scopeTypes())],
            'document.scope_id' => ['required', 'uuid'],
            'document.title' => ['required', 'string', 'max:255'],
            'document.slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'document.markdown_source' => ['required', 'string'],
            'document.state' => ['required', 'string', Rule::in(ProcedureDocument::states())],
            'document.reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($request, $procedureDocument, $scopes): void {
            $organizationId = (string) $request->input('document.organization_id');
            $scopeType = (string) $request->input('document.scope_type');
            $scopeId = (string) $request->input('document.scope_id');

            if ($organizationId !== '' && $scopeType !== '' && $scopeId !== '' && ! $scopes->isValid($organizationId, $scopeType, $scopeId)) {
                $validator->errors()->add('document.scope_id', 'The scope target must belong to the selected organization and scope type.');
            }

            $state = $request->input('document.state');
            $requiresReason = in_array($state, [ProcedureDocument::STATE_PUBLISHED, ProcedureDocument::STATE_ARCHIVED], true)
                && (! $procedureDocument->exists || $state !== $procedureDocument->state);

            if ($requiresReason && blank($request->input('document.reason'))) {
                $validator->errors()->add('document.reason', 'A reason is required when publishing or archiving a procedure.');
            }
        });

        /** @var array{document: array<string, string>} $validated */
        $validated = $validator->validate();

        return collect($validated['document'])
            ->except('reason')
            ->all();
    }
}
