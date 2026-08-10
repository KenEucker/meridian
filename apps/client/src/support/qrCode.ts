// A QR encoder, in this repository on purpose (M18.60; technical spec 13.4).
//
// The Kiosk's locked screen presents a sign-in request as a scannable code, and
// the technology baseline admits no dependency that is not already approved —
// so the encoder is first-party code rather than a package. It implements the
// subset Meridian needs and nothing else: byte mode, error-correction level M,
// versions 1 through 10 (up to 213 bytes), mask pattern 0. Every phone camera
// reads that subset; choosing the "best" mask per ISO 18004 is an optimization
// for degraded prints, not a correctness requirement, and a fixed mask keeps
// the code auditable.
//
// The structure follows ISO/IEC 18004 §7: encode the payload as a bit stream,
// split it into error-correction blocks, extend each with Reed-Solomon
// codewords over GF(256), interleave, and walk the codewords into the matrix
// in the standard two-column zigzag around the function patterns.
//
// `qrCode.spec.ts` holds the proof: it re-reads the emitted matrix with an
// independent zigzag reader, de-interleaves, recomputes the Reed-Solomon
// codewords from the extracted data, and decodes the byte stream back to the
// original text — so a placement, interleaving, or arithmetic mistake fails a
// test rather than a scan in a field.

export interface QrMatrix {
  /** Modules per side. */
  readonly size: number;
  /** `true` is a dark module. Row-major, `size` rows of `size` entries. */
  readonly modules: readonly (readonly boolean[])[];
}

/**
 * Error-correction level M block structure per version: the number of
 * error-correction codewords per block, and the blocks as (count, dataCodewords)
 * pairs. ISO 18004 table 9.
 */
export const QR_M_BLOCKS: readonly {
  readonly eccPerBlock: number;
  readonly groups: readonly (readonly [count: number, dataCodewords: number])[];
}[] = [
  { eccPerBlock: 10, groups: [[1, 16]] }, // v1
  { eccPerBlock: 16, groups: [[1, 28]] }, // v2
  { eccPerBlock: 26, groups: [[1, 44]] }, // v3
  { eccPerBlock: 18, groups: [[2, 32]] }, // v4
  { eccPerBlock: 24, groups: [[2, 43]] }, // v5
  { eccPerBlock: 16, groups: [[4, 27]] }, // v6
  { eccPerBlock: 18, groups: [[4, 31]] }, // v7
  { eccPerBlock: 22, groups: [[2, 38], [2, 39]] }, // v8
  { eccPerBlock: 22, groups: [[3, 36], [2, 37]] }, // v9
  { eccPerBlock: 26, groups: [[4, 43], [1, 44]] }, // v10
];

/** Alignment pattern centre coordinates per version. ISO 18004 annex E. */
const ALIGNMENT_POSITIONS: readonly (readonly number[])[] = [
  [], // v1
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

/* ---------------------------------------------------------------- GF(256) */

const GF_EXP = new Uint8Array(512);
const GF_LOG = new Uint8Array(256);

(function initGaloisTables(): void {
  let value = 1;

  for (let exponent = 0; exponent < 255; exponent++) {
    GF_EXP[exponent] = value;
    GF_LOG[value] = exponent;

    value <<= 1;

    if (value & 0x100) {
      value ^= 0x11d; // The QR primitive polynomial x^8+x^4+x^3+x^2+1.
    }
  }

  for (let exponent = 255; exponent < 512; exponent++) {
    GF_EXP[exponent] = GF_EXP[exponent - 255];
  }
})();

function gfMultiply(a: number, b: number): number {
  if (a === 0 || b === 0) {
    return 0;
  }

  return GF_EXP[GF_LOG[a] + GF_LOG[b]];
}

/**
 * The Reed-Solomon codewords for one block: the remainder of the data
 * polynomial times x^degree, divided by the generator polynomial whose roots
 * are the first `degree` powers of the field generator.
 */
export function reedSolomonCodewords(data: readonly number[], degree: number): number[] {
  const generator = [1];

  for (let root = 0; root < degree; root++) {
    generator.push(0);

    for (let index = generator.length - 1; index > 0; index--) {
      generator[index] = generator[index - 1] ^ gfMultiply(generator[index], GF_EXP[root]);
    }

    generator[0] = gfMultiply(generator[0], GF_EXP[root]);
  }

  const remainder = new Array<number>(degree).fill(0);

  for (const codeword of data) {
    const factor = codeword ^ remainder[0];

    remainder.shift();
    remainder.push(0);

    for (let index = 0; index < degree; index++) {
      remainder[index] ^= gfMultiply(generator[degree - 1 - index], factor);
    }
  }

  return remainder;
}

/* ----------------------------------------------------------- bit assembly */

class BitBuffer {
  readonly bits: number[] = [];

  push(value: number, length: number): void {
    for (let shift = length - 1; shift >= 0; shift--) {
      this.bits.push((value >> shift) & 1);
    }
  }
}

/** Byte-mode character-count indicator width: 8 bits through v9, 16 from v10. */
function countBits(version: number): number {
  return version <= 9 ? 8 : 16;
}

function dataCapacityBytes(version: number): number {
  const structure = QR_M_BLOCKS[version - 1];
  const dataCodewords = structure.groups.reduce(
    (total, [count, size]) => total + count * size,
    0,
  );

  return Math.floor((dataCodewords * 8 - 4 - countBits(version)) / 8);
}

function chooseVersion(byteLength: number): number {
  for (let version = 1; version <= QR_M_BLOCKS.length; version++) {
    if (byteLength <= dataCapacityBytes(version)) {
      return version;
    }
  }

  throw new RangeError(
    `QR payload of ${byteLength} bytes exceeds the ${dataCapacityBytes(QR_M_BLOCKS.length)}-byte capacity of version ${QR_M_BLOCKS.length} at level M.`,
  );
}

/** The interleaved data-plus-ECC codeword stream. ISO 18004 §7.6. */
export function buildCodewords(payload: Uint8Array, version: number): number[] {
  const structure = QR_M_BLOCKS[version - 1];
  const totalDataCodewords = structure.groups.reduce(
    (total, [count, size]) => total + count * size,
    0,
  );

  const buffer = new BitBuffer();
  buffer.push(0b0100, 4); // Byte mode.
  buffer.push(payload.length, countBits(version));

  for (const byte of payload) {
    buffer.push(byte, 8);
  }

  // Terminator, then pad to a byte boundary, then the alternating pad bytes.
  const capacityBits = totalDataCodewords * 8;
  buffer.push(0, Math.min(4, capacityBits - buffer.bits.length));

  while (buffer.bits.length % 8 !== 0) {
    buffer.bits.push(0);
  }

  const dataCodewords: number[] = [];

  for (let index = 0; index < buffer.bits.length; index += 8) {
    let byte = 0;

    for (let bit = 0; bit < 8; bit++) {
      byte = (byte << 1) | buffer.bits[index + bit];
    }

    dataCodewords.push(byte);
  }

  for (let pad = 0; dataCodewords.length < totalDataCodewords; pad++) {
    dataCodewords.push(pad % 2 === 0 ? 0xec : 0x11);
  }

  // Split into blocks, in group order.
  const blocks: number[][] = [];
  let offset = 0;

  for (const [count, size] of structure.groups) {
    for (let block = 0; block < count; block++) {
      blocks.push(dataCodewords.slice(offset, offset + size));
      offset += size;
    }
  }

  const eccBlocks = blocks.map((block) => reedSolomonCodewords(block, structure.eccPerBlock));

  // Interleave: first codeword of every block, then the second, and so on;
  // then the ECC codewords the same way.
  const interleaved: number[] = [];
  const longestBlock = Math.max(...blocks.map((block) => block.length));

  for (let index = 0; index < longestBlock; index++) {
    for (const block of blocks) {
      if (index < block.length) {
        interleaved.push(block[index]);
      }
    }
  }

  for (let index = 0; index < structure.eccPerBlock; index++) {
    for (const ecc of eccBlocks) {
      interleaved.push(ecc[index]);
    }
  }

  return interleaved;
}

/* -------------------------------------------------------------- the matrix */

/** BCH(15,5) format information for level M and a mask, pre-XORed. */
export function formatInformationBits(mask: number): number {
  // Level M is '00', so the five data bits are just the mask pattern.
  const data = mask & 0b111;
  let remainder = data << 10;

  for (let bit = 14; bit >= 10; bit--) {
    if (remainder & (1 << bit)) {
      remainder ^= 0b10100110111 << (bit - 10);
    }
  }

  return (((data << 10) | remainder) ^ 0b101010000010010) & 0x7fff;
}

/** BCH(18,6) version information, for versions 7 and up. */
export function versionInformationBits(version: number): number {
  let remainder = version << 12;

  for (let bit = 17; bit >= 12; bit--) {
    if (remainder & (1 << bit)) {
      remainder ^= 0b1111100100101 << (bit - 12);
    }
  }

  return ((version << 12) | remainder) & 0x3ffff;
}

interface MatrixBuilder {
  size: number;
  modules: boolean[][];
  /** Function modules, where data placement may not write. */
  reserved: boolean[][];
}

function newMatrix(version: number): MatrixBuilder {
  const size = version * 4 + 17;

  return {
    size,
    modules: Array.from({ length: size }, () => new Array<boolean>(size).fill(false)),
    reserved: Array.from({ length: size }, () => new Array<boolean>(size).fill(false)),
  };
}

function place(matrix: MatrixBuilder, row: number, column: number, dark: boolean): void {
  matrix.modules[row][column] = dark;
  matrix.reserved[row][column] = true;
}

function placeFinderPatterns(matrix: MatrixBuilder): void {
  const corners: readonly (readonly [number, number])[] = [
    [0, 0],
    [0, matrix.size - 7],
    [matrix.size - 7, 0],
  ];

  for (const [top, left] of corners) {
    // The 7x7 finder plus its one-module separator ring.
    for (let row = -1; row <= 7; row++) {
      for (let column = -1; column <= 7; column++) {
        const r = top + row;
        const c = left + column;

        if (r < 0 || c < 0 || r >= matrix.size || c >= matrix.size) {
          continue;
        }

        const inFinder = row >= 0 && row <= 6 && column >= 0 && column <= 6;
        const dark =
          inFinder &&
          (row === 0 || row === 6 || column === 0 || column === 6 ||
            (row >= 2 && row <= 4 && column >= 2 && column <= 4));

        place(matrix, r, c, dark);
      }
    }
  }
}

function placeTimingPatterns(matrix: MatrixBuilder): void {
  for (let index = 8; index < matrix.size - 8; index++) {
    const dark = index % 2 === 0;

    if (!matrix.reserved[6][index]) {
      place(matrix, 6, index, dark);
    }

    if (!matrix.reserved[index][6]) {
      place(matrix, index, 6, dark);
    }
  }
}

function placeAlignmentPatterns(matrix: MatrixBuilder, version: number): void {
  const positions = ALIGNMENT_POSITIONS[version - 1];

  for (const centreRow of positions) {
    for (const centreColumn of positions) {
      // Skip any centre that lands on a finder pattern.
      if (matrix.reserved[centreRow][centreColumn]) {
        continue;
      }

      for (let row = -2; row <= 2; row++) {
        for (let column = -2; column <= 2; column++) {
          const dark =
            Math.max(Math.abs(row), Math.abs(column)) !== 1;

          place(matrix, centreRow + row, centreColumn + column, dark);
        }
      }
    }
  }
}

function reserveFormatAreas(matrix: MatrixBuilder): void {
  for (let index = 0; index < 9; index++) {
    if (!matrix.reserved[8][index]) {
      place(matrix, 8, index, false);
    }

    if (!matrix.reserved[index][8]) {
      place(matrix, index, 8, false);
    }
  }

  for (let index = 0; index < 8; index++) {
    if (!matrix.reserved[8][matrix.size - 1 - index]) {
      place(matrix, 8, matrix.size - 1 - index, false);
    }

    if (!matrix.reserved[matrix.size - 1 - index][8]) {
      place(matrix, matrix.size - 1 - index, 8, false);
    }
  }

  // The module that is always dark (ISO 18004 §7.9.1).
  place(matrix, matrix.size - 8, 8, true);
}

function placeVersionInformation(matrix: MatrixBuilder, version: number): void {
  if (version < 7) {
    return;
  }

  const bits = versionInformationBits(version);

  for (let index = 0; index < 18; index++) {
    const dark = ((bits >> index) & 1) === 1;
    const row = Math.floor(index / 3);
    const column = matrix.size - 11 + (index % 3);

    // Bottom-left block and its top-right transpose.
    place(matrix, column, row, dark);
    place(matrix, row, column, dark);
  }
}

function placeFormatInformation(matrix: MatrixBuilder, mask: number): void {
  const bits = formatInformationBits(mask);
  const size = matrix.size;

  // Around the top-left finder, bit 14 first. ISO 18004 figure 25.
  const topLeft: readonly (readonly [number, number])[] = [
    [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
    [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
  ];

  // The second copy: below the top-right finder and beside the bottom-left.
  const second: readonly (readonly [number, number])[] = [
    [size - 1, 8], [size - 2, 8], [size - 3, 8], [size - 4, 8],
    [size - 5, 8], [size - 6, 8], [size - 7, 8],
    [8, size - 8], [8, size - 7], [8, size - 6], [8, size - 5],
    [8, size - 4], [8, size - 3], [8, size - 2], [8, size - 1],
  ];

  for (let index = 0; index < 15; index++) {
    const dark = ((bits >> (14 - index)) & 1) === 1;

    place(matrix, topLeft[index][0], topLeft[index][1], dark);
    place(matrix, second[index][0], second[index][1], dark);
  }
}

/**
 * Walk the codeword bits into the matrix: two-module columns from the right
 * edge, upward then downward, skipping the vertical timing column and every
 * function module. Mask 0 flips modules where (row + column) is even.
 */
function placeData(matrix: MatrixBuilder, codewords: readonly number[]): void {
  const bits: number[] = [];

  for (const codeword of codewords) {
    for (let shift = 7; shift >= 0; shift--) {
      bits.push((codeword >> shift) & 1);
    }
  }

  let bitIndex = 0;
  let upward = true;

  for (let right = matrix.size - 1; right >= 1; right -= 2) {
    if (right === 6) {
      right = 5; // The vertical timing column is not a data column.
    }

    for (let step = 0; step < matrix.size; step++) {
      const row = upward ? matrix.size - 1 - step : step;

      for (const column of [right, right - 1]) {
        if (matrix.reserved[row][column]) {
          continue;
        }

        // Bits beyond the stream stay 0, which the mask still applies to.
        const bit = bitIndex < bits.length ? bits[bitIndex] : 0;
        bitIndex += 1;

        const masked = (row + column) % 2 === 0 ? bit ^ 1 : bit;
        matrix.modules[row][column] = masked === 1;
      }
    }

    upward = !upward;
  }
}

/* ----------------------------------------------------------------- public */

/**
 * Encode text as a QR matrix at error-correction level M, mask 0.
 *
 * @throws RangeError when the payload exceeds version 10's 213-byte capacity
 */
export function encodeQrMatrix(text: string): QrMatrix {
  const payload = new TextEncoder().encode(text);
  const version = chooseVersion(payload.length);
  const codewords = buildCodewords(payload, version);

  const matrix = newMatrix(version);
  placeFinderPatterns(matrix);
  placeAlignmentPatterns(matrix, version);
  placeTimingPatterns(matrix);
  reserveFormatAreas(matrix);
  placeVersionInformation(matrix, version);
  placeData(matrix, codewords);
  placeFormatInformation(matrix, 0);

  return { size: matrix.size, modules: matrix.modules };
}

/**
 * The matrix as one SVG path, for rendering at any size with a quiet zone.
 *
 * A path rather than one rect per module keeps the locked Kiosk's DOM at one
 * element instead of a few thousand.
 */
export function qrMatrixToSvgPath(matrix: QrMatrix): string {
  const segments: string[] = [];

  for (let row = 0; row < matrix.size; row++) {
    for (let column = 0; column < matrix.size; column++) {
      if (matrix.modules[row][column]) {
        segments.push(`M${column} ${row}h1v1h-1z`);
      }
    }
  }

  return segments.join("");
}
