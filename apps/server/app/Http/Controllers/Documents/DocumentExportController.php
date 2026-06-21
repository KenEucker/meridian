<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use App\Services\Documents\DocumentExport;
use App\Services\Documents\DocumentExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Delivers document downloads through ordinary browser GET requests rather
 * than Orchid's asynchronous screen-action transport.
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

    private function download(DocumentExport $export): Response
    {
        return response($export->contents, 200, [
            'Content-Type' => $export->mimeType,
            'Content-Disposition' => 'attachment; filename="'.$export->filename.'"',
        ]);
    }
}
