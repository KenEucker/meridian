<?php

declare(strict_types=1);

namespace App\Http\Controllers\Incidents;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Models\User;
use App\Services\Downloads\ShortLivedDownloadUrlService;
use App\Services\Incidents\IncidentPdfExport;
use App\Services\Incidents\IncidentPdfExportService;
use App\Services\Incidents\IncidentPrintAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Incident PDF print/export download (INC-015; M11.10).
 *
 * GET /api/events/{event}/incidents/{incident}/pdf
 *
 * A client holding a bearer token rather than a session reaches the same file
 * through the short-lived download URL path (M16.12; CLIENT-019, CLIENT-020;
 * technical spec 11A.6): it asks for a URL and then navigates to it.
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

        return $this->generate($user, $event, $incident, $printAccess, $exports);
    }

    /**
     * Issue a short-lived URL for one incident's PDF.
     *
     * The print permission is decided here, so a caller who could not download
     * the PDF is refused the URL rather than handed one that would refuse them.
     */
    public function issueDownloadUrl(
        Request $request,
        Event $event,
        Incident $incident,
        IncidentPrintAccess $printAccess,
        ShortLivedDownloadUrlService $downloadUrls,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        // Issuance asks the question a download asks: a URL is only worth
        // issuing when the PDF behind it would be generated.
        $refusal = $this->refusal($user, $event, $incident, $printAccess);

        if ($refusal !== null) {
            return $refusal;
        }

        return response()->json($downloadUrls->issue($user, 'downloads.incidents.pdf', [
            'event' => $event->getRouteKey(),
            'incident' => $incident->getRouteKey(),
        ])->toArray());
    }

    /**
     * Serve the PDF to the user its URL was issued to.
     *
     * The navigation carries no credential, so the signature names the person
     * whose print permission is checked and whose name the export audit
     * records.
     */
    public function signedDownload(
        Request $request,
        Event $event,
        Incident $incident,
        IncidentPrintAccess $printAccess,
        IncidentPdfExportService $exports,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response|SymfonyResponse {
        return $this->generate(
            $downloadUrls->actor($request),
            $event,
            $incident,
            $printAccess,
            $exports,
        );
    }

    private function generate(
        User $user,
        Event $event,
        Incident $incident,
        IncidentPrintAccess $printAccess,
        IncidentPdfExportService $exports,
    ): Response|SymfonyResponse {
        $refusal = $this->refusal($user, $event, $incident, $printAccess);

        if ($refusal !== null) {
            return $refusal;
        }

        $export = $exports->export($incident, $user, AuditEvent::SOURCE_API);

        return $this->downloadResponse($export);
    }

    /**
     * The refusal this user is owed for this incident, or null when there is
     * none. An incident that does not belong to the event is not addressable
     * through it at all.
     */
    private function refusal(
        User $user,
        Event $event,
        Incident $incident,
        IncidentPrintAccess $printAccess,
    ): ?JsonResponse {
        if ((string) $incident->event_id !== (string) $event->id) {
            abort(404);
        }

        if (! $printAccess->canPrintIncident($user, $event)) {
            return response()->json([
                'message' => 'Only Incident Command leads for this event may print incidents to PDF.',
            ], 403);
        }

        return null;
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
