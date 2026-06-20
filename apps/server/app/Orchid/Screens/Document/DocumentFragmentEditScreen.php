<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Document;

use App\Models\DocumentFragment;
use App\Models\DocumentFragmentReference;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Orchid\Layouts\Document\DocumentFragmentEditLayout;
use App\Services\Documents\DocumentFragmentAdminService;
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

class DocumentFragmentEditScreen extends Screen
{
    /**
     * @var DocumentFragment
     */
    public $fragment;

    /**
     * @return array<string, mixed>
     */
    public function query(DocumentFragment $fragment): iterable
    {
        return [
            'fragment' => $fragment,
            'referencingDocuments' => $this->referencingDocuments($fragment),
        ];
    }

    public function name(): ?string
    {
        return $this->fragment->exists ? 'Edit Document Fragment' : 'Create Document Fragment';
    }

    public function description(): ?string
    {
        return 'Reusable Markdown content. Referencing documents are listed below before any edit is saved.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return ['platform.document-fragments'];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Cancel'))
                ->icon('bs.x-circle')
                ->route('platform.document-fragments'),

            Button::make(__('Save Fragment Changes'))
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
            Layout::block(DocumentFragmentEditLayout::class)
                ->title(__('Document fragment'))
                ->description(__('Fragments are Markdown-only reusable content without lifecycle states.')),
            Layout::block(Layout::view('orchid.documents.referencing-documents'))
                ->title(__('Referencing documents')),
        ];
    }

    public function save(
        Request $request,
        DocumentFragment $fragment,
        DocumentFragmentAdminService $fragments,
        DocumentScopeValidator $scopes,
    ): RedirectResponse {
        $validated = $this->validateFragment($request, $fragment, $scopes);

        try {
            $fragments->save($fragment, $validated, $request->user());
        } catch (DocumentFragmentReferenceException $exception) {
            throw ValidationException::withMessages([
                'fragment.markdown_source' => $exception->getMessage(),
            ]);
        }

        Toast::info(__('Document fragment was saved.'));

        return redirect()->route('platform.document-fragments');
    }

    /**
     * @return array<string, string>
     */
    private function validateFragment(
        Request $request,
        DocumentFragment $fragment,
        DocumentScopeValidator $scopes,
    ): array {
        $organizationId = $request->input('fragment.organization_id');

        $validator = Validator::make($request->all(), [
            'fragment.organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'fragment.scope_type' => ['required', 'string', Rule::in(DocumentFragment::scopeTypes())],
            'fragment.scope_id' => ['required', 'uuid'],
            'fragment.name' => ['required', 'string', 'max:255'],
            'fragment.slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(DocumentFragment::class, 'slug')
                    ->where('organization_id', $organizationId)
                    ->ignore($fragment),
            ],
            'fragment.markdown_source' => ['required', 'string'],
        ]);

        $validator->after(function ($validator) use ($request, $scopes): void {
            $organizationId = (string) $request->input('fragment.organization_id');
            $scopeType = (string) $request->input('fragment.scope_type');
            $scopeId = (string) $request->input('fragment.scope_id');

            if ($organizationId !== '' && $scopeType !== '' && $scopeId !== '' && ! $scopes->isValid($organizationId, $scopeType, $scopeId)) {
                $validator->errors()->add('fragment.scope_id', 'The scope target must belong to the selected organization and scope type.');
            }
        });

        /** @var array{fragment: array<string, string>} $validated */
        $validated = $validator->validate();

        return $validated['fragment'];
    }

    /**
     * @return list<array{type: string, title: string, scope: string, state: string, version: string, route: string, id: string}>
     */
    private function referencingDocuments(DocumentFragment $fragment): array
    {
        if (! $fragment->exists) {
            return [];
        }

        $referenceIds = DocumentFragmentReference::query()
            ->where('fragment_id', $fragment->id)
            ->get(['document_type', 'document_id'])
            ->groupBy('document_type')
            ->map(fn ($references) => $references->pluck('document_id')->all());

        return [
            ...$this->documentReferenceRows(
                PolicyDocument::class,
                $referenceIds->get(DocumentFragmentReference::DOCUMENT_TYPE_POLICY, []),
                'Policy',
                'platform.policy-documents.edit',
            ),
            ...$this->documentReferenceRows(
                ProcedureDocument::class,
                $referenceIds->get(DocumentFragmentReference::DOCUMENT_TYPE_PROCEDURE, []),
                'Procedure',
                'platform.procedure-documents.edit',
            ),
        ];
    }

    /**
     * @param  class-string<PolicyDocument|ProcedureDocument>  $model
     * @param  list<string>  $ids
     * @return list<array{type: string, title: string, scope: string, state: string, version: string, route: string, id: string}>
     */
    private function documentReferenceRows(string $model, array $ids, string $type, string $route): array
    {
        if ($ids === []) {
            return [];
        }

        return $model::query()
            ->with(['organizationScope', 'departmentScope', 'teamScope'])
            ->whereIn('id', $ids)
            ->orderBy('title')
            ->get()
            ->map(fn (PolicyDocument|ProcedureDocument $document): array => [
                'type' => $type,
                'title' => $document->title,
                'scope' => $this->scopeLabel($document),
                'state' => $document::stateLabels()[$document->state],
                'version' => $document->version(),
                'route' => $route,
                'id' => $document->id,
            ])
            ->all();
    }

    private function scopeLabel(PolicyDocument|ProcedureDocument $document): string
    {
        return match ($document->scope_type) {
            PolicyDocument::SCOPE_ORGANIZATION => __('Organization').': '.($document->organizationScope?->name ?? __('Not configured')),
            PolicyDocument::SCOPE_DEPARTMENT => __('Department').': '.($document->departmentScope?->name ?? __('Not configured')),
            PolicyDocument::SCOPE_TEAM => __('Team').': '.($document->teamScope?->name ?? __('Not configured')),
            default => ucfirst($document->scope_type),
        };
    }
}
