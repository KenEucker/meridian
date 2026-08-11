<?php

declare(strict_types=1);

namespace App\Http\Controllers\Navigation;

use App\Domain\Navigation\MenuPageCatalog;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Navigation\PageVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/commands/set-menu-page-visibility` — one user taking one page out
 * of their own menus, or putting it back (M18.69).
 *
 * The narrower sibling of {@see PageVisibilityController}. That command puts a
 * page away entirely; this one only shortens a menu, and the page it names
 * stays on the home directory, stays linked, and stays reachable by address.
 *
 * Self-scoped in the same strong sense: the request carries a page and an
 * answer and no subject at all, so there is no parameter anyone could point at
 * somebody else's menu. Not audited — personal view state is not a record of
 * anything operational (technical spec 21D.10).
 *
 * The response returns the caller's whole resolved list rather than an
 * acknowledgement of the one page, so a client that has just written a
 * preference has the state to render from without reconstructing one from
 * defaults it would have to keep in step with this node's.
 */
final class MenuVisibilityController extends Controller
{
    public function __construct(
        private readonly PageVisibilityService $pages,
    ) {}

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'page_key' => ['required', 'string', 'in:'.implode(',', MenuPageCatalog::keys())],
            'hidden' => ['required', 'boolean'],
        ]);

        $this->pages->setMenuHidden($user, (string) $validated['page_key'], (bool) $validated['hidden']);

        return response()->json([
            'menu_hidden_pages' => $this->pages->menuHiddenPagesFor($user),
        ]);
    }
}
