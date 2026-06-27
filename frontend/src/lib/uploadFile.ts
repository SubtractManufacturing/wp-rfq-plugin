export async function uploadFile(
  uploadUrl: string,
  file: File,
  contentType: string,
  onProgress: (pct: number) => void,
  fetchImpl: typeof fetch = fetch,
): Promise<void> {
  onProgress(0);
  const response = await fetchImpl(uploadUrl, {
    method: "PUT",
    body: file,
    headers: { "Content-Type": contentType },
  });

  if (!response.ok) {
    throw new Error(`S3 upload failed: ${response.status}`);
  }

  onProgress(100);
}
