<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ClientAppController extends Controller
{
    public function __invoke(): BinaryFileResponse|Response
    {
        return $this->indexResponse();
    }

    public function asset(string $clientAssetPath): BinaryFileResponse
    {
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
