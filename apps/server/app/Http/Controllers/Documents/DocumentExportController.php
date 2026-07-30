<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Services\Documents\DocumentExport;
use App\Services\Documents\DocumentExportService;
use App\Services\Downloads\ShortLivedDownloadUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Delivers document downloads through ordinary browser GET requests rather
 * than Orchid's asynchronous screen-action transport.
 *
 * A client holding a bearer token rather than a session reaches the same file
 * through the short-lived download URL path (M16.12; CLIENT-019, CLIENT-020;
 * technical spec 11A.6): it asks for a URL and then navigates to it.
 */
class DocumentExportController extends Controller
{
    public function policy(
        Request $request,
        PolicyDocument $policyDocument,
        string $format,
        DocumentExportService $exports,
    ): Response {
        return $this->download($exports->export(
            $policyDocument,
            $request->user(),
            $format,
            AuditEvent::SOURCE_ORCHID,
        ));
    }

    public function apiPolicy(
        Request $request,
        PolicyDocument $policyDocument,
        string $format,
        DocumentExportService $exports,
    ): Response {
        return $this->download($exports->export(
            $policyDocument,
            $request->user(),
            $format,
            AuditEvent::SOURCE_API,
        ));
    }

    public function procedure(
        Request $request,
        ProcedureDocument $procedureDocument,
        string $format,
        DocumentExportService $exports,
    ): Response {
        return $this->download($exports->export(
            $procedureDocument,
            $request->user(),
            $format,
            AuditEvent::SOURCE_ORCHID,
        ));
    }

    public function apiProcedure(
        Request $request,
        ProcedureDocument $procedureDocument,
        string $format,
        DocumentExportService $exports,
    ): Response {
        return $this->download($exports->export(
            $procedureDocument,
            $request->user(),
            $format,
            AuditEvent::SOURCE_API,
        ));
    }

    /**
     * Issue a short-lived URL for one document in one format (M16.12;
     * CLIENT-019, CLIENT-020; technical spec 11A.6; data/API 5.7).
     *
     * The format is part of the resource the URL is scoped to, so a URL issued
     * for the Markdown export does not retrieve the PDF.
     */
    public function issuePolicyDownloadUrl(
        Request $request,
        PolicyDocument $policyDocument,
        string $format,
        DocumentExportService $exports,
        ShortLivedDownloadUrlService $downloadUrls,
    ): JsonResponse {
        return $this->issue(
            $request,
            $policyDocument,
            $format,
            $exports,
            $downloadUrls,
            'downloads.policy-documents.export',
            'policyDocument',
        );
    }

    public function issueProcedureDownloadUrl(
        Request $request,
        ProcedureDocument $procedureDocument,
        string $format,
        DocumentExportService $exports,
        ShortLivedDownloadUrlService $downloadUrls,
    ): JsonResponse {
        return $this->issue(
            $request,
            $procedureDocument,
            $format,
            $exports,
            $downloadUrls,
            'downloads.procedure-documents.export',
            'procedureDocument',
        );
    }

    /**
     * Serve a document export to the user its URL was issued to.
     *
     * The navigation carries no credential, so the signature names the person
     * whose export authorization is checked and whose name the export audit
     * records.
     */
    public function signedPolicy(
        Request $request,
        PolicyDocument $policyDocument,
        string $format,
        DocumentExportService $exports,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response {
        return $this->download($exports->export(
            $policyDocument,
            $downloadUrls->actor($request),
            $format,
            AuditEvent::SOURCE_API,
        ));
    }

    public function signedProcedure(
        Request $request,
        ProcedureDocument $procedureDocument,
        string $format,
        DocumentExportService $exports,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response {
        return $this->download($exports->export(
            $procedureDocument,
            $downloadUrls->actor($request),
            $format,
            AuditEvent::SOURCE_API,
        ));
    }

    private function issue(
        Request $request,
        PolicyDocument|ProcedureDocument $document,
        string $format,
        DocumentExportService $exports,
        ShortLivedDownloadUrlService $downloadUrls,
        string $routeName,
        string $routeParameter,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $exports->canExport($document, $user)) {
            return response()->json([
                'message' => 'You are not authorized to export this document.',
            ], 403);
        }

        return response()->json($downloadUrls->issue($user, $routeName, [
            $routeParameter => $document->getRouteKey(),
            'format' => $format,
        ])->toArray());
    }

    private function download(DocumentExport $export): Response
    {
        return response($export->contents, 200, [
            'Content-Type' => $export->mimeType,
            'Content-Disposition' => 'attachment; filename="'.$export->filename.'"',
        ]);
    }
}
