<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Import;

use App\Models\User;
use App\Services\Imports\ImportException;
use App\Services\Imports\ImportResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * Shared behavior for the Alpha 1 Orchid / God Mode imports (technical
 * spec 22.2).
 *
 * Every import offers the same two actions. **Preview** runs the file and rolls
 * it back, so an operator sees the exact per-row outcome before anything is
 * written; **Import** runs it for real. Both report one line per row, because a
 * single count cannot tell an operator which row of their spreadsheet was
 * wrong, and one bad row never aborts the file.
 *
 * The file may be uploaded or pasted. An upload may be the `.xlsx` workbook the
 * operator built the roster in or a CSV exported from it — the format is
 * detected from the file's contents rather than its name, so neither choice is
 * a wrong one. Pasting takes CSV, and is what makes this usable over a slow
 * on-site link and from a terminal session.
 */
abstract class ImportScreen extends Screen
{
    /**
     * Maximum upload size in kilobytes. These files are lists of names and
     * codes; anything larger is a wrong-file mistake, not an import.
     */
    private const MAX_UPLOAD_KILOBYTES = 2048;

    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'import_result' => session('import_result'),
        ];
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.imports',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Preview'))
                ->icon('bs.eyeglasses')
                ->method('preview'),

            Button::make(__('Import'))
                ->icon('bs.box-arrow-in-down')
                ->method('import')
                ->confirm(__('Apply this file? Rows that match an existing record update it, and every change is audited.')),
        ];
    }

    /**
     * @return string[]|\Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            $this->formLayout(),
            Layout::view('orchid.import-results'),
        ];
    }

    public function preview(Request $request): RedirectResponse
    {
        return $this->applyImport($request, true);
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->applyImport($request, false);
    }

    /**
     * Route this screen redirects back to after a run.
     */
    abstract protected function routeName(): string;

    /**
     * The screen's file/paste form.
     */
    abstract protected function formLayout(): string;

    abstract protected function runImport(string $file, User $actor, bool $preview): ImportResult;

    private function applyImport(Request $request, bool $preview): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->hasAccess('platform.imports'), 403);

        $request->validate([
            'file' => ['nullable', 'file', 'max:'.self::MAX_UPLOAD_KILOBYTES],
            'csv' => ['nullable', 'string'],
        ]);

        $file = $this->fileFrom($request);

        if ($file === null) {
            Toast::warning(__('Choose a spreadsheet or CSV file, or paste CSV rows first.'));

            return redirect()->route($this->routeName());
        }

        try {
            $result = $this->runImport($file, $user, $preview);
        } catch (ImportException $exception) {
            Toast::warning(__($exception->getMessage()));

            return redirect()->route($this->routeName());
        }

        $message = __(':imported created, :updated updated, :skipped skipped.', [
            'imported' => $result->imported(),
            'updated' => $result->updated(),
            'skipped' => $result->skipped(),
        ]);

        if ($preview) {
            Toast::info(__('Preview only — nothing was saved. :summary', ['summary' => $message]));
        } else {
            Toast::info($message);
        }

        return redirect()
            ->route($this->routeName())
            ->with('import_result', $result->toArray());
    }

    /**
     * An uploaded file wins over pasted text, so an operator who picks a file
     * and forgets to clear an earlier paste imports the file they just chose.
     *
     * The contents are returned as read. A workbook is binary and is passed on
     * whole; only a text paste can be meaningfully blank.
     */
    private function fileFrom(Request $request): ?string
    {
        $file = $request->file('file');

        if ($file !== null && ! is_array($file) && $file->isValid()) {
            $contents = (string) file_get_contents($file->getRealPath());

            return trim($contents) === '' ? null : $contents;
        }

        $pasted = (string) $request->input('csv', '');

        return trim($pasted) === '' ? null : $pasted;
    }
}
