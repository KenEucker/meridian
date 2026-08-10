<?php

declare(strict_types=1);

namespace App\Http\Controllers\Navigation;

use App\Domain\Navigation\HideablePageCatalog;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Navigation\PageVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/commands/set-page-visibility` — one user hiding or restoring one
 * page in their own navigation (M18.69).
 *
 * Self-scoped in the strongest sense available: the request carries a page and
 * an answer and no subject at all, so there is no parameter anyone could point
 * at somebody else's menu. Not audited — personal view state is not a record of
 * anything operational (technical spec 21D.10).
 *
 * The response returns the caller's whole resolved list rather than an
 * acknowledgement of the one page. A client that has just written a preference
 * needs the state to render from, and handing back the complete answer means it
 * never has to reconstruct one by applying defaults it would have to keep in
 * step with this node's.
 */
final class PageVisibilityController extends Controller
{
    public function __construct(
        private readonly PageVisibilityService $pages,
    ) {}

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'page_key' => ['required', 'string', 'in:'.implode(',', HideablePageCatalog::keys())],
            'hidden' => ['required', 'boolean'],
        ]);

        $this->pages->setHidden($user, (string) $validated['page_key'], (bool) $validated['hidden']);

        return response()->json([
            'hidden_pages' => $this->pages->hiddenPagesFor($user),
        ]);
    }
}
