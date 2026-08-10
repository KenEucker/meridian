// The proof behind the first-party QR encoder (M18.60; technical spec 13.4).
//
// Nothing here trusts the encoder's own tables twice: the reader below walks
// the emitted matrix with its own zigzag, strips the mask by re-deriving which
// modules are function modules, de-interleaves with the shared block table,
// recomputes the Reed-Solomon codewords from the extracted data, and decodes
// the byte stream back to the original text. A mistake in placement,
// interleaving, masking, or field arithmetic fails here rather than in a
// phone camera in a field.

import { describe, expect, it } from "vitest";

import {
  buildCodewords,
  encodeQrMatrix,
  formatInformationBits,
  QR_M_BLOCKS,
  qrMatrixToSvgPath,
  reedSolomonCodewords,
  versionInformationBits,
  type QrMatrix,
} from "./qrCode";

/**
 * Re-derive the function-module map for a matrix of this size, independently
 * of the encoder's `reserved` bookkeeping: finders with separators, timing
 * lines, alignment patterns, format areas, the dark module, and version blocks.
 */
function functionModuleMap(size: number): boolean[][] {
  const version = (size - 17) / 4;
  const reserved = Array.from({ length: size }, () => new Array<boolean>(size).fill(false));

  const mark = (row: number, column: number): void => {
    if (row >= 0 && column >= 0 && row < size && column < size) {
      reserved[row][column] = true;
    }
  };

  for (const [top, left] of [
    [0, 0],
    [0, size - 7],
    [size - 7, 0],
  ] as const) {
    for (let row = -1; row <= 7; row++) {
      for (let column = -1; column <= 7; column++) {
        mark(top + row, left + column);
      }
    }
  }

  for (let index = 0; index < size; index++) {
    mark(6, index);
    mark(index, 6);
  }

  const alignmentPositions: readonly (readonly number[])[] = [
    [],
    [6, 18],
    [6, 22],
    [6, 26],
    [6, 30],
    [6, 34],
    [6, 22, 38],
    [6, 24, 42],
    [6, 26, 46],
    [6, 28, 50],
  ];

  for (const centreRow of alignmentPositions[version - 1]) {
    for (const centreColumn of alignmentPositions[version - 1]) {
      const overlapsFinder =
        (centreRow <= 8 && centreColumn <= 8) ||
        (centreRow <= 8 && centreColumn >= size - 9) ||
        (centreRow >= size - 9 && centreColumn <= 8);

      if (overlapsFinder) {
        continue;
      }

      for (let row = -2; row <= 2; row++) {
        for (let column = -2; column <= 2; column++) {
          mark(centreRow + row, centreColumn + column);
        }
      }
    }
  }

  for (let index = 0; index < 9; index++) {
    mark(8, index);
    mark(index, 8);
  }

  for (let index = 0; index < 8; index++) {
    mark(8, size - 1 - index);
    mark(size - 1 - index, 8);
  }

  if (version >= 7) {
    for (let index = 0; index < 18; index++) {
      const row = Math.floor(index / 3);
      const column = size - 11 + (index % 3);

      mark(column, row);
      mark(row, column);
    }
  }

  return reserved;
}

/** An independent zigzag reader: the inverse of the encoder's placement. */
function readCodewords(matrix: QrMatrix): number[] {
  const reserved = functionModuleMap(matrix.size);
  const bits: number[] = [];

  let upward = true;

  for (let right = matrix.size - 1; right >= 1; right -= 2) {
    if (right === 6) {
      right = 5;
    }

    for (let step = 0; step < matrix.size; step++) {
      const row = upward ? matrix.size - 1 - step : step;

      for (const column of [right, right - 1]) {
        if (reserved[row][column]) {
          continue;
        }

        const masked = matrix.modules[row][column] ? 1 : 0;
        bits.push((row + column) % 2 === 0 ? masked ^ 1 : masked);
      }
    }

    upward = !upward;
  }

  const codewords: number[] = [];

  for (let index = 0; index + 8 <= bits.length; index += 8) {
    let byte = 0;

    for (let bit = 0; bit < 8; bit++) {
      byte = (byte << 1) | bits[index + bit];
    }

    codewords.push(byte);
  }

  return codewords;
}

/** De-interleave and decode a byte-mode stream back to text. */
function decodePayload(matrix: QrMatrix): string {
  const version = (matrix.size - 17) / 4;
  const structure = QR_M_BLOCKS[version - 1];
  const interleaved = readCodewords(matrix);

  const blockSizes: number[] = [];

  for (const [count, size] of structure.groups) {
    for (let block = 0; block < count; block++) {
      blockSizes.push(size);
    }
  }

  const totalData = blockSizes.reduce((total, size) => total + size, 0);
  const blocks: number[][] = blockSizes.map(() => []);
  const longest = Math.max(...blockSizes);

  let cursor = 0;

  for (let index = 0; index < longest; index++) {
    for (let block = 0; block < blocks.length; block++) {
      if (index < blockSizes[block]) {
        blocks[block].push(interleaved[cursor]);
        cursor += 1;
      }
    }
  }

  // The extracted ECC codewords must equal a recomputation from the extracted
  // data — this is what catches interleaving and placement mistakes.
  const eccBlocks: number[][] = blocks.map(() => []);

  for (let index = 0; index < structure.eccPerBlock; index++) {
    for (let block = 0; block < blocks.length; block++) {
      eccBlocks[block].push(interleaved[cursor]);
      cursor += 1;
    }
  }

  for (let block = 0; block < blocks.length; block++) {
    expect(eccBlocks[block]).toEqual(reedSolomonCodewords(blocks[block], structure.eccPerBlock));
  }

  const data = blocks.flat();
  expect(data).toHaveLength(totalData);

  // Byte mode: 4-bit mode indicator, then the count, then the bytes.
  const bitAt = (index: number): number => (data[Math.floor(index / 8)] >> (7 - (index % 8))) & 1;
  const readBits = (start: number, length: number): number => {
    let value = 0;

    for (let index = 0; index < length; index++) {
      value = (value << 1) | bitAt(start + index);
    }

    return value;
  };

  expect(readBits(0, 4)).toBe(0b0100);

  const countBits = version <= 9 ? 8 : 16;
  const length = readBits(4, countBits);
  const bytes: number[] = [];

  for (let index = 0; index < length; index++) {
    bytes.push(readBits(4 + countBits + index * 8, 8));
  }

  return new TextDecoder().decode(new Uint8Array(bytes));
}

describe("encodeQrMatrix", () => {
  it("round-trips a short payload through a version-1 matrix", () => {
    const matrix = encodeQrMatrix("MERIDIAN");

    expect(matrix.size).toBe(21);
    expect(decodePayload(matrix)).toBe("MERIDIAN");
  });

  it("round-trips the sign-in request payload shape at its real size", () => {
    const text =
      "meridian:workstation-sign-in?v=1" +
      "&w=0198c111-2222-7333-8444-555566667777" +
      "&n=0198c888-9999-7aaa-8bbb-ccccddddeeee" +
      "&r=0198cfff-0000-7111-8222-333344445555" +
      "&nn=onsite-command-1";

    const matrix = encodeQrMatrix(text);

    expect(decodePayload(matrix)).toBe(text);
  });

  it("round-trips every version's largest payload, including the multi-block ones", () => {
    for (let version = 1; version <= 10; version++) {
      const structure = QR_M_BLOCKS[version - 1];
      const dataCodewords = structure.groups.reduce(
        (total, [count, size]) => total + count * size,
        0,
      );
      const capacity = Math.floor((dataCodewords * 8 - 4 - (version <= 9 ? 8 : 16)) / 8);
      const text = "M".repeat(capacity);

      const matrix = encodeQrMatrix(text);

      expect(matrix.size).toBe(version * 4 + 17);
      expect(decodePayload(matrix)).toBe(text);
    }
  });

  it("refuses a payload beyond version 10's capacity rather than emitting a wrong code", () => {
    expect(() => encodeQrMatrix("M".repeat(214))).toThrowError(RangeError);
  });

  it("places the finder patterns, the timing pattern, and the dark module", () => {
    const matrix = encodeQrMatrix("MERIDIAN");

    // The three finder centres are dark; the separator ring is light.
    for (const [row, column] of [
      [3, 3],
      [3, matrix.size - 4],
      [matrix.size - 4, 3],
    ] as const) {
      expect(matrix.modules[row][column]).toBe(true);
    }

    expect(matrix.modules[7][7]).toBe(false);

    // The timing pattern alternates, dark on even coordinates.
    for (let index = 8; index < matrix.size - 8; index++) {
      expect(matrix.modules[6][index]).toBe(index % 2 === 0);
      expect(matrix.modules[index][6]).toBe(index % 2 === 0);
    }

    // The always-dark module (ISO 18004 §7.9.1).
    expect(matrix.modules[matrix.size - 8][8]).toBe(true);
  });

  it("emits the published format information for level M mask 0", () => {
    // ISO 18004's own worked example values.
    expect(formatInformationBits(0)).toBe(0b101010000010010);
  });

  it("emits the published version information for version 7", () => {
    // ISO 18004 annex D's worked example.
    expect(versionInformationBits(7)).toBe(0b000111110010010100);
  });

  it("keeps codeword counts at the level-M totals", () => {
    const expectedTotals = [26, 44, 70, 100, 134, 172, 196, 242, 292, 346];

    for (let version = 1; version <= 10; version++) {
      const codewords = buildCodewords(new TextEncoder().encode("M"), version);

      expect(codewords).toHaveLength(expectedTotals[version - 1]);
    }
  });
});

describe("qrMatrixToSvgPath", () => {
  it("draws one path segment per dark module", () => {
    const matrix = encodeQrMatrix("MERIDIAN");
    const darkModules = matrix.modules.flat().filter(Boolean).length;
    const path = qrMatrixToSvgPath(matrix);

    expect(path.match(/M\d+ \d+h1v1h-1z/g)).toHaveLength(darkModules);
  });
});
