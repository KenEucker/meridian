/**
 * Dependency-free Alpha 1 PDF text renderer (client mirror of
 * App\Services\Documents\PdfDocumentRenderer).
 */

const PAGE_WIDTH = 595;
const PAGE_HEIGHT = 842;
const MARGIN = 50;
const LINE_HEIGHT = 14;
const MAX_LINES_PER_PAGE = 52;
const WRAP_WIDTH = 86;

export function renderSimplePdf(lines: readonly string[]): Uint8Array {
  const pages = chunk(wrapLines(lines), MAX_LINES_PER_PAGE);
  const pageChunks = pages.length === 0 ? [[]] : pages;

  const objects = new Map<number, string>([
    [1, "<< /Type /Catalog /Pages 2 0 R >>"],
    [3, "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"],
  ]);
  const pageIds: number[] = [];

  pageChunks.forEach((pageLines, index) => {
    const pageId = 4 + index * 2;
    const contentId = pageId + 1;
    pageIds.push(pageId);
    const stream = contentStream(pageLines);
    objects.set(
      pageId,
      `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${PAGE_WIDTH} ${PAGE_HEIGHT}] /Resources << /Font << /F1 3 0 R >> >> /Contents ${contentId} 0 R >>`,
    );
    objects.set(
      contentId,
      `<< /Length ${byteLength(stream)} >>\nstream\n${stream}\nendstream`,
    );
  });

  objects.set(
    2,
    `<< /Type /Pages /Kids [${pageIds.map((id) => `${id} 0 R`).join(" ")}] /Count ${pageIds.length} >>`,
  );

  return assemblePdf(objects);
}

function wrapLines(lines: readonly string[]): string[] {
  const wrapped: string[] = [];

  for (const line of lines) {
    for (const part of wordWrap(line, WRAP_WIDTH)) {
      wrapped.push(part);
    }
  }

  return wrapped;
}

function wordWrap(line: string, width: number): string[] {
  if (line.length <= width) {
    return [line];
  }

  const words = line.split(/\s+/u).filter(Boolean);
  if (words.length === 0) {
    return [""];
  }

  const rows: string[] = [];
  let current = "";

  for (const word of words) {
    if (current === "") {
      current = word;
      continue;
    }

    if (`${current} ${word}`.length <= width) {
      current = `${current} ${word}`;
      continue;
    }

    rows.push(current);
    current = word;
  }

  if (current !== "") {
    rows.push(current);
  }

  return rows;
}

function contentStream(lines: readonly string[]): string {
  const commands = [
    "BT",
    "/F1 10 Tf",
    `${MARGIN} ${PAGE_HEIGHT - MARGIN} Td`,
  ];

  for (const line of lines) {
    commands.push(`(${escapeText(line)}) Tj`);
    commands.push(`0 -${LINE_HEIGHT} Td`);
  }

  commands.push("ET");
  return commands.join("\n");
}

function escapeText(text: string): string {
  const ascii = text.replace(/[^\x20-\x7E]/gu, "?");
  return ascii
    .replaceAll("\\", "\\\\")
    .replaceAll("(", "\\(")
    .replaceAll(")", "\\)")
    .replaceAll("\r", "")
    .replaceAll("\n", "");
}

function assemblePdf(objects: Map<number, string>): Uint8Array {
  const encoder = new TextEncoder();
  const chunks: Uint8Array[] = [];
  let offset = 0;
  const offsets = new Map<number, number>();

  const push = (value: string): void => {
    const bytes = encoder.encode(value);
    chunks.push(bytes);
    offset += bytes.length;
  };

  push("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");

  const ids = [...objects.keys()].sort((left, right) => left - right);
  for (const id of ids) {
    offsets.set(id, offset);
    push(`${id} 0 obj\n${objects.get(id)}\nendobj\n`);
  }

  const xrefOffset = offset;
  push(`xref\n0 ${ids.length + 1}\n`);
  push("0000000000 65535 f \n");
  for (const id of ids) {
    push(`${String(offsets.get(id) ?? 0).padStart(10, "0")} 00000 n \n`);
  }
  push(`trailer << /Size ${ids.length + 1} /Root 1 0 R >>\n`);
  push(`startxref\n${xrefOffset}\n%%EOF\n`);

  const total = chunks.reduce((sum, chunk) => sum + chunk.length, 0);
  const output = new Uint8Array(total);
  let cursor = 0;
  for (const chunk of chunks) {
    output.set(chunk, cursor);
    cursor += chunk.length;
  }

  return output;
}

function chunk<T>(items: readonly T[], size: number): T[][] {
  const pages: T[][] = [];
  for (let index = 0; index < items.length; index += size) {
    pages.push(items.slice(index, index + size));
  }
  return pages;
}

function byteLength(value: string): number {
  return new TextEncoder().encode(value).length;
}
