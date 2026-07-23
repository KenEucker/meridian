<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\AuditEvent;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Generates and audits single-document Markdown and PDF exports.
 *
 * Packet assembly, API delivery, and client-side/offline export remain outside
 * this Alpha 1 slice (POL-028, POL-029, POL-031; data/API section 11.11).
 */
class DocumentExportService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DocumentRenderer $renderer,
        private readonly DocumentScopeValidator $scopes,
        private readonly PdfDocumentRenderer $pdf,
        private readonly DocumentProductAccess $access,
    ) {}

    public function export(
        PolicyDocument|ProcedureDocument $document,
        User $actor,
        string $format,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): DocumentExport {
        $this->assertActorCanExport($document, $actor);

        if (! in_array($format, DocumentExport::formats(), true)) {
            throw new InvalidArgumentException("Unsupported document export format '{$format}'.");
        }

        $documentType = $document instanceof PolicyDocument ? 'Policy' : 'Procedure';
        $scope = $document::scopeTypeLabels()[$document->scope_type] ?? Str::headline($document->scope_type);
        $exportedAt = now()->utc();
        $resolvedMarkdown = $this->renderer->resolvedMarkdown($document);
        $markdown = $this->markdownExport($document, $documentType, $scope, $exportedAt->toIso8601String(), $resolvedMarkdown);
        $contents = $format === DocumentExport::FORMAT_MARKDOWN
            ? $markdown
            : $this->pdf->render($this->pdfLines($document, $documentType, $scope, $exportedAt->toIso8601String()));

        $export = new DocumentExport(
            format: $format,
            contents: $contents,
            filename: $this->filename($document, $format),
            mimeType: DocumentExport::mimeTypeFor($format),
            documentType: $documentType,
            version: $document->version(),
            scope: $scope,
            exportedAt: $exportedAt,
        );

        $this->audit->recordForEntity(
            entity: $document,
            action: $document instanceof PolicyDocument ? 'policy_document.exported' : 'procedure_document.exported',
            actorUser: $actor,
            organizationId: $document->organization_id,
            departmentId: $this->scopes->departmentId($document->scope_type, $document->scope_id),
            after: [
                'format' => $export->format,
                'document_type' => strtolower($export->documentType),
                'document_version' => $export->version,
                'scope_type' => $document->scope_type,
                'exported_at' => $export->exportedAt->toIso8601String(),
            ],
            sourceContext: $sourceContext,
        );

        return $export;
    }

    private function assertActorCanExport(PolicyDocument|ProcedureDocument $document, User $actor): void
    {
        $permission = $document instanceof PolicyDocument
            ? 'platform.policy-documents'
            : 'platform.procedure-documents';

        if (! $actor->hasAccess($permission) && ! $this->access->canMaintainDocument($actor, $document)) {
            throw new AuthorizationException('You are not authorized to export this document.');
        }
    }

    private function markdownExport(
        PolicyDocument|ProcedureDocument $document,
        string $documentType,
        string $scope,
        string $exportTimestamp,
        string $resolvedMarkdown,
    ): string {
        return implode("\n", [
            '# '.$document->title,
            '',
            '- Document type: '.$documentType,
            '- Document title: '.$document->title,
            '- Document version: '.$document->version(),
            '- Scope: '.$scope,
            '- Exported at: '.$exportTimestamp,
            '',
            '---',
            '',
            trim($resolvedMarkdown),
            '',
        ]);
    }

    /**
     * @return list<string>
     */
    private function pdfLines(
        PolicyDocument|ProcedureDocument $document,
        string $documentType,
        string $scope,
        string $exportTimestamp,
    ): array {
        return [
            'Document type: '.$documentType,
            'Document title: '.$document->title,
            'Document version: '.$document->version(),
            'Scope: '.$scope,
            'Exported at: '.$exportTimestamp,
            '',
            ...$this->renderedTextLines($this->renderer->render($document)),
        ];
    }

    /**
     * @return list<string>
     */
    private function renderedTextLines(string $html): array
    {
        $withBreaks = preg_replace(
            '/<\/?(?:h[1-6]|p|div|blockquote|pre|ul|ol|li|br)[^>]*>/i',
            "\n",
            $html,
        ) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return array_values(array_filter(
            explode("\n", trim($text)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    private function filename(PolicyDocument|ProcedureDocument $document, string $format): string
    {
        $type = $document instanceof PolicyDocument ? 'policy' : 'procedure';
        $title = Str::slug($document->title) ?: 'document';
        $version = str_replace('.', '-', $document->version());

        return "{$type}-{$title}-{$version}.".DocumentExport::extensionFor($format);
    }
}
