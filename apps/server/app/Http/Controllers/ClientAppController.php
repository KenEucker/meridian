<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ClientAppController extends Controller
{
    public function __invoke(): BinaryFileResponse|Response
    {
        return $this->indexResponse();
    }

    public function asset(string $clientAssetPath): BinaryFileResponse|RedirectResponse
    {
        if ($this->usesDevServer()) {
            abort_if(preg_match('#(^|/)\.\.(?:/|$)#', $clientAssetPath) === 1, 404);

            return redirect()->away($this->devServerUrl('assets/'.$clientAssetPath));
        }

        $root = realpath($this->distPath());
        abort_if($root === false, 404);

        $assetRoot = realpath($root.DIRECTORY_SEPARATOR.'assets');
        abort_if($assetRoot === false, 404);

        $file = realpath($assetRoot.DIRECTORY_SEPARATOR.$clientAssetPath);
        abort_if($file === false || ! str_starts_with($file, $assetRoot.DIRECTORY_SEPARATOR), 404);
        abort_if(! is_file($file), 404);

        return response()->file($file, [
            'Content-Type' => $this->contentType($file),
        ]);
    }

    private function indexResponse(): BinaryFileResponse|Response
    {
        if ($this->usesDevServer()) {
            return response($this->devIndexHtml())
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $index = $this->distPath('index.html');

        if (is_file($index)) {
            return response()->file($index, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        return response()
            ->view('client.missing')
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function distPath(string $path = ''): string
    {
        $root = rtrim((string) config('meridian.client.dist_path'), DIRECTORY_SEPARATOR);

        return $path === '' ? $root : $root.DIRECTORY_SEPARATOR.$path;
    }

    private function usesDevServer(): bool
    {
        return (bool) config('meridian.client.use_dev_server');
    }

    private function devIndexHtml(): string
    {
        $viteClientUrl = htmlspecialchars($this->devServerUrl('@vite/client'), ENT_QUOTES, 'UTF-8');
        $entryUrl = htmlspecialchars($this->devServerUrl('src/main.ts'), ENT_QUOTES, 'UTF-8');
        $runtimeConfig = $this->runtimeConfigJson();

        return <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>Meridian Admin</title>
    <script type="module" src="{$viteClientUrl}"></script>
  </head>
  <body>
    <div id="app"></div>
    <script>window.__MERIDIAN_RUNTIME_CONFIG__ = {$runtimeConfig};</script>
    <script type="module" src="{$entryUrl}"></script>
  </body>
</html>
HTML;
    }

    private function runtimeConfigJson(): string
    {
        $json = json_encode(
            [
                'apiBaseUrl' => request()->getSchemeAndHttpHost(),
                'deploymentTarget' => 'server',
                'uiMode' => 'admin',
            ],
            JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES,
        );

        return $json === false ? '{}' : $json;
    }

    private function devServerUrl(string $path = ''): string
    {
        $root = rtrim((string) config('meridian.client.dev_server_url'), '/');
        abort_if($root === '', 503, 'Meridian client development server URL is not configured.');

        return $path === '' ? $root : $root.'/'.ltrim($path, '/');
    }

    private function contentType(string $file): string
    {
        return match (pathinfo($file, PATHINFO_EXTENSION)) {
            'css' => 'text/css; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
            'ico' => 'image/x-icon',
            'js' => 'text/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
