export type HttpMethod = "GET" | "POST" | "PATCH" | "PUT";

export interface ApiFetchOptions {
  method?: HttpMethod;
  token?: string | null;
  body?: unknown;
  signal?: AbortSignal;
  restBase?: string;
  fetchImpl?: typeof fetch;
}

export class ApiError extends Error {
  code: string;
  status: number;
  fields: Record<string, string>;

  constructor(message: string, code: string, status: number, fields: Record<string, string> = {}) {
    super(message);
    this.name = "ApiError";
    this.code = code;
    this.status = status;
    this.fields = fields;
  }
}

export interface SessionResponse {
  session_id: string;
  token: string;
}

export interface UploadUrlResponse {
  upload_url: string;
  file_key: string;
}

export interface SubmitResponse {
  receipt_number: string;
}
