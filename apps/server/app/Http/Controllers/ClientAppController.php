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

    /**
     * How long the address that answered is worth leading with. Longer than
     * the reachability answer itself, because which address a dev server binds
     * outlives any one run of it.
     */
    private const ADDRESS_MEMORY_TTL_SECONDS = 3600;

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
        $addresses = $this->devServerSocketAddresses();

        if ($addresses === []) {
            return false;
        }

        return (bool) Cache::remember(
            'meridian:client-dev-server-reachable:'.implode(',', $addresses),
            self::REACHABILITY_TTL_SECONDS,
            fn (): bool => $this->anyAddressAccepts($addresses),
        );
    }

    /**
     * Every address the configured dev server might be listening on.
     *
     * A hostname is resolved here rather than left to the connect, because
     * PHP's own resolution picks one address family without regard to the one
     * the dev server bound, and does not fall back: on Windows a `localhost`
     * connect reports a demonstrably open port as unreachable. Trusting it
     * would fall back to the build while the dev server was running, which is
     * a quieter and more confusing failure than the blank page this exists to
     * fix.
     *
     * Both loopback families are candidates, because binding one of them is
     * not a choice this server makes: Vite may hold `::1` alone, and reporting
     * that as no dev server is the same wrong answer from the other direction.
     *
     * @return list<string>
     */
    private function devServerSocketAddresses(): array
    {
        $url = rtrim((string) config('meridian.client.dev_server_url'), '/');

        if ($url === '') {
            return [];
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return [];
        }

        $host = trim($host, '[]');
        $port = parse_url($url, PHP_URL_PORT)
            ?? (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $hosts = [$host];
        } else {
            // Returns the hostname unchanged when it does not resolve.
            $hosts = gethostbynamel($host) ?: [];

            // `localhost` resolves from the hosts file rather than DNS, so its
            // IPv6 form is not something a lookup here reliably returns.
            if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
                $hosts[] = '::1';
            }
        }

        $addresses = [];

        foreach ($hosts as $candidate) {
            $addresses[] = str_contains($candidate, ':')
                ? 'tcp://['.$candidate.']:'.$port
                : 'tcp://'.$candidate.':'.$port;
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether any candidate address accepts a connection.
     *
     * Tried in turn, leading with whichever address answered last time. The
     * ordering is what keeps the second family cheap: a closed port does not
     * always refuse — Windows drops it, costing the whole timeout — so a dev
     * server holding only `::1` would otherwise pay for the IPv4 attempt on
     * every check. It answers for one, and every check after that starts
     * there.
     *
     * Connecting to each address in turn rather than to all at once is
     * deliberate. `STREAM_CLIENT_ASYNC_CONNECT` does not defer the attempt on
     * Windows, so starting them together and selecting over the results
     * measured slower than this in all three cases it was meant to improve.
     *
     * @param  list<string>  $addresses
     */
    private function anyAddressAccepts(array $addresses): bool
    {
        $memoryKey = 'meridian:client-dev-server-address:'.implode(',', $addresses);
        $remembered = Cache::get($memoryKey);

        if (is_string($remembered) && in_array($remembered, $addresses, true)) {
            $addresses = [
                $remembered,
                ...array_values(array_diff($addresses, [$remembered])),
            ];
        }

        foreach ($addresses as $address) {
            $socket = @stream_socket_client(
                $address,
                $errorNumber,
                $errorMessage,
                self::CONNECT_TIMEOUT_SECONDS,
                STREAM_CLIENT_CONNECT,
            );

            if ($socket !== false) {
                fclose($socket);

                Cache::put($memoryKey, $address, self::ADDRESS_MEMORY_TTL_SECONDS);

                return true;
            }
        }

        return false;
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
