<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Document;

use App\Models\PolicyDocument;
use App\Orchid\Layouts\Document\DocumentEditLayout;
use App\Services\Documents\DocumentAdminService;
use App\Services\Documents\DocumentExport;
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

class PolicyDocumentEditScreen extends Screen
{
    /**
     * @var PolicyDocument
     */
    public $document;

    /**
     * @return array<string, PolicyDocument>
     */
    public function query(PolicyDocument $policyDocument): iterable
    {
        return [
            'document' => $policyDocument->load('fragmentReferences.fragment'),
        ];
    }

    public function name(): ?string
    {
        return $this->document->exists ? 'Edit Policy' : 'Create Policy';
    }

    public function description(): ?string
    {
        return 'Edit Markdown source and review the saved rendered policy below.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return ['platform.policy-documents'];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        $actions = [
            Link::make(__('Cancel'))
                ->icon('bs.x-circle')
                ->route('platform.policy-documents'),
        ];

        if ($this->document->exists) {
            $actions[] = Link::make(__('Export Markdown'))
                ->icon('bs.download')
                ->route('platform.policy-documents.export', [
                    'policyDocument' => $this->document,
                    'format' => DocumentExport::FORMAT_MARKDOWN,
                ]);
            $actions[] = Link::make(__('Export PDF'))
                ->icon('bs.file-earmark-pdf')
                ->route('platform.policy-documents.export', [
                    'policyDocument' => $this->document,
                    'format' => DocumentExport::FORMAT_PDF,
                ]);
        }

        $actions[] = Button::make(__('Save Policy'))
            ->icon('bs.check-circle')
            ->method('save');

        return $actions;
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(new DocumentEditLayout(PolicyDocument::class))
                ->title(__('Policy'))
                ->description(__('Policy source remains Markdown with explicit fragment references.')),
            Layout::block(Layout::view('orchid.documents.fragment-references'))
                ->title(__('Fragment references')),
            Layout::block(Layout::view('orchid.documents.preview'))
                ->title(__('Rendered preview')),
        ];
    }

    public function save(
        Request $request,
        PolicyDocument $policyDocument,
        DocumentAdminService $documents,
        DocumentScopeValidator $scopes,
    ): RedirectResponse {
        $validated = $this->validateDocument($request, $policyDocument, $scopes);

        try {
            $documents->save(
                $policyDocument,
                $validated,
                $request->user(),
                $request->input('document.reason'),
            );
        } catch (DocumentFragmentReferenceException $exception) {
            throw ValidationException::withMessages([
                'document.markdown_source' => $exception->getMessage(),
            ]);
        }

        Toast::info(__('Policy was saved.'));

        return redirect()->route('platform.policy-documents');
    }

    /**
     * @return array<string, string>
     */
    private function validateDocument(
        Request $request,
        PolicyDocument $policyDocument,
        DocumentScopeValidator $scopes,
    ): array {
        $validator = Validator::make($request->all(), [
            'document.organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'document.scope_type' => ['required', 'string', Rule::in(PolicyDocument::scopeTypes())],
            'document.scope_id' => ['required', 'uuid'],
            'document.title' => ['required', 'string', 'max:255'],
            'document.slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'document.markdown_source' => ['required', 'string'],
            'document.state' => ['required', 'string', Rule::in(PolicyDocument::states())],
            'document.reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($request, $policyDocument, $scopes): void {
            $organizationId = (string) $request->input('document.organization_id');
            $scopeType = (string) $request->input('document.scope_type');
            $scopeId = (string) $request->input('document.scope_id');

            if ($organizationId !== '' && $scopeType !== '' && $scopeId !== '' && ! $scopes->isValid($organizationId, $scopeType, $scopeId)) {
                $validator->errors()->add('document.scope_id', 'The scope target must belong to the selected organization and scope type.');
            }

            $state = $request->input('document.state');
            $requiresReason = in_array($state, [PolicyDocument::STATE_PUBLISHED, PolicyDocument::STATE_ARCHIVED], true)
                && (! $policyDocument->exists || $state !== $policyDocument->state);

            if ($requiresReason && blank($request->input('document.reason'))) {
                $validator->errors()->add('document.reason', 'A reason is required when publishing or archiving a policy.');
            }
        });

        /** @var array{document: array<string, string>} $validated */
        $validated = $validator->validate();

        return collect($validated['document'])
            ->except('reason')
            ->all();
    }
}
