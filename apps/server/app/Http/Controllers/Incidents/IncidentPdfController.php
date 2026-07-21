<?php

declare(strict_types=1);

namespace App\Http\Controllers\Incidents;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Services\Incidents\IncidentPdfExport;
use App\Services\Incidents\IncidentPdfExportService;
use App\Services\Incidents\IncidentPrintAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Incident PDF print/export download (INC-015; M11.10).
 *
 * GET /api/events/{event}/incidents/{incident}/pdf
 */
final class IncidentPdfController extends Controller
{
    public function download(
        Request $request,
        Event $event,
        Incident $incident,
        IncidentPrintAccess $printAccess,
        IncidentPdfExportService $exports,
    ): Response|SymfonyResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ((string) $incident->event_id !== (string) $event->id) {
            abort(404);
        }

        if (! $printAccess->canPrintIncident($user, $event)) {
            return response()->json([
                'message' => 'Only Incident Command leads for this event may print incidents to PDF.',
            ], 403);
        }

        $export = $exports->export($incident, $user, AuditEvent::SOURCE_API);

        return $this->downloadResponse($export);
    }

    private function downloadResponse(IncidentPdfExport $export): Response
    {
        return response($export->contents, 200, [
            'Content-Type' => IncidentPdfExport::MIME_TYPE,
            'Content-Disposition' => 'attachment; filename="'.$export->filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
