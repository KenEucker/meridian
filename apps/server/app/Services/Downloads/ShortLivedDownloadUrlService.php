<?php

declare(strict_types=1);

namespace App\Services\Downloads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Short-lived scoped download URLs (CLIENT-019, CLIENT-020; technical spec
 * 11A.6; data/API 5.7).
 *
 * A bearer token cannot be attached to a plain browser navigation, so an
 * authenticated client asks for a URL and then navigates to it. The Field
 * Report photo endpoints established this shape; this service is that shape
 * with the resource left open, so exports and generated documents reach it too.
 *
 * Two things make the URL scoped rather than a general-purpose credential. The
 * signature covers the route and its parameters, so a URL issued for one
 * resource cannot be edited into a URL for another without invalidating itself.
 * And it carries the user it was issued to, because the file behind an export
 * depends on who asked for it: the same request from an organizer and from a
 * department lead are different files. Navigation arrives with no session and
 * no token, so without that name the follow-up could not know whose export to
 * generate or whose name to record in the audit.
 */
final class ShortLivedDownloadUrlService
{
    /** Query parameter naming the user a URL was issued to. */
    public const ACTOR_PARAMETER = 'actor';

    /**
     * Issue a URL for one resource, valid for one user, for a few minutes.
     *
     * @param  array<string, mixed>  $parameters  Route parameters identifying the single resource.
     */
    public function issue(User $user, string $routeName, array $parameters = []): ShortLivedDownloadUrl
    {
        $expiresAt = CarbonImmutable::now()->addMinutes($this->expiryMinutes());

        $relativeSignedUrl = URL::temporarySignedRoute(
            $routeName,
            $expiresAt,
            [...$parameters, self::ACTOR_PARAMETER => (string) $user->getKey()],
            absolute: false,
        );

        return new ShortLivedDownloadUrl(URL::to($relativeSignedUrl), $expiresAt);
    }

    /**
     * Resolve the user a signed download URL was issued to.
     *
     * The signature has already been verified by the `signed:relative`
     * middleware by the time this runs, so the name it carries is the node's
     * own and not the caller's claim about themselves.
     */
    public function actor(Request $request): User
    {
        $actorId = $request->query(self::ACTOR_PARAMETER);

        if (! is_string($actorId) || $actorId === '') {
            throw new AccessDeniedHttpException('This download link does not say who it was issued to.');
        }

        $user = User::query()->find($actorId);

        if ($user === null) {
            throw new AccessDeniedHttpException('The user this download link was issued to no longer exists.');
        }

        return $user;
    }

    /**
     * How long an issued URL lives.
     *
     * Short enough that a link left in a browser history or a chat message is
     * usually already dead, long enough that a slow network still finishes the
     * navigation the client just started.
     */
    public function expiryMinutes(): int
    {
        return max(1, (int) config('meridian.downloads.signed_url_expires_minutes', 5));
    }
}
