<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the Meridian Admin client: the built artifact, or the Vite dev server
 * shell while a developer is running one.
 *
 * Dev-server mode is configuration (`MERIDIAN_CLIENT_USE_DEV_SERVER`, on by
 * default in `local`), and it used to be taken at its word: the shell it
 * returns carries nothing but two module tags pointing at the dev server, so a
 * node running with the flag on and no dev server behind it served a page that
 * could only ever render blank. The flag says what a developer intends to run,
 * which is not the same fact as what is running.
 *
 * So the mode is now confirmed before it is used. When the dev server does not
 * answer, the request falls back to the built artifact and the response says so
 * in {@see self::CLIENT_SOURCE_HEADER} — the build is by definition older than
 * the working tree, and "my edit is not showing" deserves an answer that is not
 * a guess. A node with no build either resolves the `client.missing` view it
 * always did.
 *
 * The check is a TCP connect rather than a request, because whether something
 * is listening is the whole question, and the answer is cached for
 * {@see self::REACHABILITY_TTL_SECONDS}. The cache is not only about cost. A
 * page and the assets it references are separate requests, and a mode that
 * changed between them would serve a built page whose assets redirect to a dev
 * server that is not there; one answer for the whole burst keeps them agreeing.
 */
class ClientAppController extends Controller
{
    /**
     * Names where a served page came from when dev-server mode is configured:
     * present with `build-fallback` when the dev server did not answer, absent
     * when it did.
     */
    public const CLIENT_SOURCE_HEADER = 'X-Meridian-Client-Source';

    /**
     * How long one reachability answer stands. Short enough that starting the
     * dev server takes effect on the next reload rather than the next session,
     * long enough to cover a page and every asset it pulls.
     */
    private const REACHABILITY_TTL_SECONDS = 5;

    /**
     * Sized for a dev server on the loopback interface, where an open port
     * completes in single-digit milliseconds. A connect that takes longer than
     * this is treated as no dev server, which costs a fallback to the build
     * rather than a failure.
     */
    private const CONNECT_TIMEOUT_SECONDS = 0.3;

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

        return $this->noteClientSource(response()->file($file, [
            'Content-Type' => $this->contentType($file),
        ]));
    }

    private function indexResponse(): BinaryFileResponse|Response
    {
        if ($this->usesDevServer()) {
            return response($this->devIndexHtml())
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $index = $this->distPath('index.html');

        if (is_file($index)) {
            return $this->noteClientSource(
                response($this->builtIndexHtml($index))
                    ->header('Content-Type', 'text/html; charset=UTF-8'),
            );
        }

        return $this->noteClientSource(response()
            ->view('client.missing')
            ->header('Content-Type', 'text/html; charset=UTF-8'));
    }

    /**
     * Mark a response that served the build while dev-server mode was on.
     *
     * @template TResponse of BinaryFileResponse|Response
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    private function noteClientSource(BinaryFileResponse|Response $response): BinaryFileResponse|Response
    {
        if ($this->devServerIsConfigured()) {
            $response->headers->set(self::CLIENT_SOURCE_HEADER, 'build-fallback');
        }

        return $response;
    }

    private function distPath(string $path = ''): string
    {
        $root = rtrim((string) config('meridian.client.dist_path'), DIRECTORY_SEPARATOR);

        return $path === '' ? $root : $root.DIRECTORY_SEPARATOR.$path;
    }

    private function usesDevServer(): bool
    {
        return $this->devServerIsConfigured() && $this->devServerIsReachable();
    }

    private function devServerIsConfigured(): bool
    {
        return (bool) config('meridian.client.use_dev_server');
    }

    /**
     * Whether anything is listening where the dev server is configured to be.
     */
    private function devServerIsReachable(): bool
    {
        $address = $this->devServerSocketAddress();

        if ($address === null) {
            return false;
        }

        return (bool) Cache::remember(
            'meridian:client-dev-server-reachable:'.$address,
            self::REACHABILITY_TTL_SECONDS,
            static function () use ($address): bool {
                $connection = @stream_socket_client(
                    $address,
                    $errorNumber,
                    $errorMessage,
                    self::CONNECT_TIMEOUT_SECONDS,
                    STREAM_CLIENT_CONNECT,
                );

                if ($connection === false) {
                    return false;
                }

                fclose($connection);

                return true;
            },
        );
    }

    /**
     * The configured dev server as a socket address, or null when there is no
     * usable one to connect to.
     *
     * A hostname is resolved here rather than left to the connect, because
     * PHP's own resolution picks an address family without regard to the one
     * the dev server bound: on Windows a `localhost` connect reports a
     * demonstrably open port as unreachable, which would fall back to the build
     * while Vite was running — a quieter and more confusing failure than the one
     * this fallback exists to fix.
     */
    private function devServerSocketAddress(): ?string
    {
        $url = rtrim((string) config('meridian.client.dev_server_url'), '/');

        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT)
            ?? (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            // Returns the hostname unchanged when it does not resolve.
            $resolved = gethostbyname($host);

            if ($resolved === $host) {
                return null;
            }

            $host = $resolved;
        }

        return str_contains($host, ':')
            ? 'tcp://['.$host.']:'.$port
            : 'tcp://'.$host.':'.$port;
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
    <link rel="icon" href="/favicon.ico" sizes="any" />
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
        return $this->encodeRuntimeConfig([
            'apiBaseUrl' => request()->getSchemeAndHttpHost(),
            'deploymentTarget' => 'server',
            'uiMode' => 'admin',
        ]);
    }

    /**
     * The built client, told which node served it.
     *
     * `nodeConnection.ts` resolves the node a device talks to from, in order, a
     * node it has been configured with, the node that served it, and only then
     * the URL baked in at build time — and it names the browser client as the
     * one that never needs the last of those, "served by a node, which injects
     * its own origin". Nothing injected it here until now, so a browser reached
     * the built artifact and called whatever host the artifact happened to be
     * built against. Browse the same node by another name it answers to — an
     * organization subdomain (technical spec 8.7), `localhost` where the build
     * baked `127.0.0.1` — and every call left the origin and was refused by
     * CORS, on a page the node had just served itself.
     *
     * Only the origin is injected. The mode is the build's own fact, carried in
     * `__MERIDIAN_DEPLOYMENT_TARGET__`, and stating it here would let this
     * server relabel an artifact it does not identify.
     *
     * The script is inline and classic, so it runs before the deferred module
     * that reads it whatever order the document puts them in.
     */
    private function builtIndexHtml(string $index): string
    {
        $html = (string) file_get_contents($index);

        $script = '<script>window.__MERIDIAN_RUNTIME_CONFIG__ = '
            .$this->encodeRuntimeConfig(['apiBaseUrl' => request()->getSchemeAndHttpHost()])
            .';</script>';

        $head = stripos($html, '</head>');

        if ($head !== false) {
            return substr_replace($html, $script, $head, 0);
        }

        $body = stripos($html, '<body');

        if ($body !== false) {
            $bodyOpenEnd = strpos($html, '>', $body);

            if ($bodyOpenEnd !== false) {
                return substr_replace($html, $script, $bodyOpenEnd + 1, 0);
            }
        }

        return $script.$html;
    }

    /**
     * @param  array<string, string>  $config
     */
    private function encodeRuntimeConfig(array $config): string
    {
        $json = json_encode(
            $config,
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
