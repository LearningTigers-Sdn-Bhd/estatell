import { expect, test } from 'vite-plus/test';
import { buildOutputReport, parseInventoryReport } from './ecrfid';

const request = [0xec, 0x07, 0xff, 0xfd, 0x01, 0x00, 0xaa, 0xbb];

test('unnumbered output report is [length, frame, padding] at the descriptor size', () => {
    const device = {
        collections: [
            {
                outputReports: [
                    {
                        reportId: 0,
                        items: [{ reportSize: 8, reportCount: 64 }],
                    },
                ],
            },
        ],
    };
    const { id, data } = buildOutputReport(device, request);

    expect(id).toBe(0);
    expect(data.length).toBe(64);
    expect(Array.from(data.slice(0, 9))).toEqual([8, ...request]);
});

test('numbered output report uses the frame length as ID', () => {
    const device = {
        collections: [{ outputReports: [{ reportId: 8, items: [] }] }],
    };
    const { id, data } = buildOutputReport(device, request);

    expect(id).toBe(8);
    expect(Array.from(data)).toEqual(request);
});

test('a reply padded with zeros (Windows) still parses; the empty end marker does not', () => {
    // Captured from a Windows PC: report 12 carries the UID, report 8 is the "no more data" marker.
    const card = [
        0xec, 0x0b, 0x01, 0xfd, 0x01, 0x00, 0xc6, 0x30, 0x2d, 0x8a, 0x35, 0x62,
    ];
    const end = [0xec, 0x07, 0x01, 0xfd, 0x01, 0x00, 0x00, 0x5c];
    const padded = (f: number[]) => [
        ...f,
        ...Array.from({ length: 51 - f.length }, () => 0),
    ];

    expect(parseInventoryReport(padded(card))).toEqual([
        0xc6, 0x30, 0x2d, 0x8a,
    ]);
    expect(parseInventoryReport(padded(end))).toBeNull();
});

test('inventory reply parses in both numbered and unnumbered layouts', () => {
    const body = [0x0b, 0xff, 0xfd, 0x01, 0x00, 0x0d, 0xd2, 0x5c, 0xe7];
    let crc = 0xffff;
    for (const b of body) {
        crc ^= b;
        for (let i = 0; i < 8; i++) {
            crc = crc & 1 ? (crc >> 1) ^ 0xa001 : crc >> 1;
        }
    }
    const frame = [0xec, ...body, crc & 0xff, crc >> 8];

    expect(parseInventoryReport(frame)).toEqual([0x0d, 0xd2, 0x5c, 0xe7]);
    expect(parseInventoryReport([frame.length, ...frame, 0, 0, 0])).toEqual([
        0x0d, 0xd2, 0x5c, 0xe7,
    ]);
});
