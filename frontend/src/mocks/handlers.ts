import { http, HttpResponse } from "msw";
import { DEV_REST_BASE, DEV_SESSION_ID, DEV_TOKEN } from "./devConfig";
const S3_ORIGIN = "https://s3.test";

function apiPath(suffix: string): string {
  return `${DEV_REST_BASE}${suffix}`;
}

export const devMockHandlers = [
  http.get(apiPath("/health"), () => HttpResponse.json({ status: "ok" })),

  http.post(apiPath("/sessions"), () =>
    HttpResponse.json({
      session_id: DEV_SESSION_ID,
      token: DEV_TOKEN,
    }),
  ),

  http.patch(apiPath(`/sessions/${DEV_SESSION_ID}/contact`), async ({ request }) => {
    const body = (await request.json()) as { first_name?: string };
    return HttpResponse.json({ first_name: body.first_name ?? "Dev" });
  }),

  http.post(apiPath(`/sessions/${DEV_SESSION_ID}/upload-urls`), async ({ request }) => {
    const body = (await request.json()) as {
      part_id?: string;
      file_type?: string;
      filename?: string;
    };
    const filename = body.filename ?? "file.bin";
    const folder = body.file_type === "drawing" ? "drawings" : "parts";
    const fileKey = `intake/${DEV_SESSION_ID}/${folder}/${filename}`;

    return HttpResponse.json({
      upload_url: `${S3_ORIGIN}/${encodeURIComponent(filename)}`,
      file_key: fileKey,
    });
  }),

  http.options(`${S3_ORIGIN}/*`, () =>
    new HttpResponse(null, {
      status: 200,
      headers: {
        "Access-Control-Allow-Origin": "*",
        "Access-Control-Allow-Methods": "PUT, OPTIONS",
        "Access-Control-Allow-Headers": "Content-Type",
      },
    }),
  ),

  http.put(`${S3_ORIGIN}/*`, () => new HttpResponse(null, { status: 200 })),

  http.put(apiPath(`/sessions/${DEV_SESSION_ID}/draft`), () => HttpResponse.json({ saved: true })),

  http.post(apiPath(`/sessions/${DEV_SESSION_ID}/refresh`), () =>
    HttpResponse.json({
      session_id: DEV_SESSION_ID,
      token: DEV_TOKEN,
    }),
  ),

  http.post(apiPath(`/sessions/${DEV_SESSION_ID}/submit`), () =>
    HttpResponse.json({
      receipt_number: "RFQ-DEV-000001",
    }),
  ),
];
