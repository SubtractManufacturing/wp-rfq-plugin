import { ApiError, type ApiFetchOptions } from "../types/api";
import { getConfig } from "../config";

interface WpErrorBody {
  code?: string;
  message?: string;
  data?: {
    status?: number;
    fields?: Record<string, string>;
  };
}

export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const restBase = options.restBase ?? getConfig().restBase;
  const fetchImpl = options.fetchImpl ?? fetch;
  const headers: Record<string, string> = {
    Accept: "application/json",
  };

  if (options.body !== undefined) {
    headers["Content-Type"] = "application/json";
  }

  if (options.token) {
    headers.Authorization = `Bearer ${options.token}`;
  }

  const response = await fetchImpl(`${restBase.replace(/\/$/, "")}${path}`, {
    method: options.method ?? "GET",
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    signal: options.signal,
  });

  const body = await parseJson(response);

  if (!response.ok) {
    const errorBody = (body ?? {}) as WpErrorBody;
    throw new ApiError(
      errorBody.message ?? "Request failed",
      errorBody.code ?? "rfq_request_failed",
      errorBody.data?.status ?? response.status,
      errorBody.data?.fields ?? {},
    );
  }

  return body as T;
}

async function parseJson(response: Response): Promise<unknown> {
  const text = await response.text();
  if (text.trim() === "") {
    return null;
  }
  return JSON.parse(text) as unknown;
}
