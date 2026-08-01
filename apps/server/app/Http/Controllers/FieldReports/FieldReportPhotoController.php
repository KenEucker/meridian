<?php

namespace App\Http\Controllers\FieldReports;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\FieldReport;
use App\Models\User;
use App\Services\Downloads\ShortLivedDownloadUrlService;
use App\Services\FieldReports\FieldReportPhotoSignedUrlService;
use App\Services\FieldReports\FieldReportPhotoUploadService;
use App\Services\Node\NodeSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Field Report photo upload and signed preview/download (M9.8).
 *
 * Technical spec 18.5–18.6; data/API 10.17.
 */
class FieldReportPhotoController extends Controller
{
    public function __construct(private readonly NodeSetupService $nodes) {}

    public function upload(
        Request $request,
        FieldReportPhotoUploadService $uploads,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'id' => ['required', 'uuid'],
            'field_report_id' => ['required', 'uuid'],
            'origin_device_id' => ['required', 'uuid'],
            // Optional for the same reason the text command's is (M16.21): the
            // browser uploading the photo cannot learn a node id, and the node
            // receiving the upload is the one it originated at.
            'origin_node_id' => ['nullable', 'uuid'],
            'checksum_sha256' => ['required', 'string', 'size:64'],
            'declared_mime_type' => ['nullable', 'string', 'max:128'],
            'device_uploaded_at' => ['nullable', 'date'],
            'bytes_base64' => ['required', 'string'],
        ]);

        $originNodeId = $validated['origin_node_id']
            ?? $this->nodes->activeNode()?->getKey();

        if ($originNodeId === null) {
            return response()->json([
                'message' => 'This node is not configured, so a Field Report photo cannot record where it came from.',
            ], 422);
        }

        $report = FieldReport::query()->findOrFail($validated['field_report_id']);
        abort_unless($user->can('uploadPhoto', $report), 403);

        $bytes = base64_decode($validated['bytes_base64'], true);
        if ($bytes === false || $bytes === '') {
            return response()->json([
                'message' => 'Field Report photo bytes_base64 is invalid.',
            ], 422);
        }

        try {
            $attachment = $uploads->upload([
                'id' => $validated['id'],
                'field_report_id' => $validated['field_report_id'],
                'uploaded_by_user_id' => (string) $user->getKey(),
                'origin_device_id' => $validated['origin_device_id'],
                'origin_node_id' => (string) $originNodeId,
                'bytes' => $bytes,
                'checksum_sha256' => strtolower($validated['checksum_sha256']),
                'declared_mime_type' => $validated['declared_mime_type'] ?? null,
                'device_uploaded_at' => $validated['device_uploaded_at'] ?? null,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'id' => $attachment->id,
            'field_report_id' => $attachment->attachable_id,
            'filename' => $attachment->filename,
            'mime_type' => $attachment->mime_type,
            'byte_size' => $attachment->byte_size,
            'checksum' => $attachment->checksum,
            'created_at' => optional($attachment->created_at)?->toIso8601String(),
        ], 201);
    }

    public function issuePreviewUrl(
        Request $request,
        Attachment $attachment,
        FieldReportPhotoSignedUrlService $signedUrls,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        try {
            $url = $signedUrls->previewUrl($user, $attachment);
        } catch (Throwable $exception) {
            throw new AccessDeniedHttpException($exception->getMessage());
        }

        return response()->json($url->toArray());
    }

    public function issueDownloadUrl(
        Request $request,
        Attachment $attachment,
        FieldReportPhotoSignedUrlService $signedUrls,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        try {
            $url = $signedUrls->downloadUrl($user, $attachment);
        } catch (Throwable $exception) {
            throw new AccessDeniedHttpException($exception->getMessage());
        }

        return response()->json($url->toArray());
    }

    public function preview(
        Request $request,
        Attachment $attachment,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response {
        return $this->stream($downloadUrls->actor($request), $attachment, asDownload: false);
    }

    public function download(
        Request $request,
        Attachment $attachment,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response {
        return $this->stream($downloadUrls->actor($request), $attachment, asDownload: true);
    }

    /**
     * Serve the blob to the user the signed URL was issued to.
     *
     * The navigation carries no session and no bearer token — that is the whole
     * reason the URL exists — so the person is named by the signature. Their
     * policy runs again here rather than being taken on trust from issuance:
     * it is the same decision about the same person, not a second credential to
     * present, and re-running it means a permission withdrawn a minute ago
     * takes effect now instead of when the link expires.
     */
    private function stream(User $user, Attachment $attachment, bool $asDownload): Response
    {
        if (! $attachment->isFieldReportPhoto()) {
            throw new NotFoundHttpException();
        }

        $report = $attachment->attachable;
        if (! $report instanceof FieldReport) {
            $report = FieldReport::query()->find($attachment->attachable_id);
        }
        abort_unless($report instanceof FieldReport, 404);

        if ($asDownload) {
            abort_unless($user->can('downloadPhoto', $report), 403);
        } else {
            abort_unless($user->can('view', $report), 403);
        }

        if (! Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) {
            throw new NotFoundHttpException('Field Report photo blob is not available.');
        }

        $bytes = Storage::disk($attachment->storage_disk)->get($attachment->storage_path);
        $disposition = $asDownload ? 'attachment' : 'inline';

        return response($bytes, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Length' => (string) $attachment->byte_size,
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $disposition,
                addslashes($attachment->filename),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
