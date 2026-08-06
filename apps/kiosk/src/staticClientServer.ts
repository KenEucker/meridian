import { createReadStream, existsSync, statSync } from "node:fs";
import { createServer, type IncomingMessage, type Server, type ServerResponse } from "node:http";
import { extname, resolve, sep } from "node:path";

export interface ClientStaticServer {
  url: string;
  close: () => Promise<void>;
}

export interface ClientStaticServerOptions {
  distDir: string;
  host?: string;
  port?: number;
}

const CONTENT_TYPES: Record<string, string> = {
  ".css": "text/css; charset=UTF-8",
  ".html": "text/html; charset=UTF-8",
  ".ico": "image/x-icon",
  ".js": "text/javascript; charset=UTF-8",
  ".json": "application/json; charset=UTF-8",
  ".png": "image/png",
  ".svg": "image/svg+xml",
  ".webp": "image/webp",
};

export function contentTypeForPath(filePath: string): string {
  return CONTENT_TYPES[extname(filePath)] ?? "application/octet-stream";
}

export function resolveClientFile(distDir: string, requestPath: string): string | null {
  const root = resolve(distDir);
  const pathname = requestPath === "/" ? "/index.html" : requestPath;
  const normalizedPath = decodeURIComponent(pathname).replace(/^\/+/, "");
  const candidate = resolve(root, normalizedPath);

  if (candidate !== root && !candidate.startsWith(`${root}${sep}`)) {
    return null;
  }

  if (existsSync(candidate) && statSync(candidate).isFile()) {
    return candidate;
  }

  const indexPath = resolve(root, "index.html");
  return existsSync(indexPath) && statSync(indexPath).isFile() ? indexPath : null;
}

export async function startClientStaticServer(
  options: ClientStaticServerOptions,
): Promise<ClientStaticServer> {
  const host = options.host ?? "127.0.0.1";
  const port = options.port ?? 0;
  const server = createServer((request, response) => {
    serveClientRequest(options.distDir, request, response);
  });

  await new Promise<void>((resolveListen, rejectListen) => {
    server.once("error", rejectListen);
    server.listen(port, host, () => {
      server.off("error", rejectListen);
      resolveListen();
    });
  });

  const address = server.address();
  if (address === null || typeof address === "string") {
    throw new Error("Unable to determine local client server address.");
  }

  return {
    url: `http://${host}:${address.port}/`,
    close: () => closeServer(server),
  };
}

function serveClientRequest(
  distDir: string,
  request: IncomingMessage,
  response: ServerResponse,
): void {
  if (request.method !== "GET" && request.method !== "HEAD") {
    response.writeHead(405, { Allow: "GET, HEAD" });
    response.end();
    return;
  }

  const url = new URL(request.url ?? "/", "http://127.0.0.1");
  const filePath = resolveClientFile(distDir, url.pathname);
  if (filePath === null) {
    response.writeHead(503, { "Content-Type": "text/html; charset=UTF-8" });
    response.end("<!doctype html><title>Meridian client build unavailable</title>");
    return;
  }

  response.writeHead(200, { "Content-Type": contentTypeForPath(filePath) });

  if (request.method === "HEAD") {
    response.end();
    return;
  }

  createReadStream(filePath).pipe(response);
}

async function closeServer(server: Server): Promise<void> {
  await new Promise<void>((resolveClose, rejectClose) => {
    server.close((error) => {
      if (error) {
        rejectClose(error);
        return;
      }
      resolveClose();
    });
  });
}
