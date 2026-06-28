export async function uploadFile(
  uploadUrl: string,
  file: File,
  contentType: string,
  onProgress: (pct: number) => void,
  fetchImpl?: typeof fetch,
): Promise<void> {
  if (fetchImpl) {
    await uploadWithFetch(uploadUrl, file, contentType, onProgress, fetchImpl);
    return;
  }

  await uploadWithXhr(uploadUrl, file, contentType, onProgress);
}

async function uploadWithFetch(
  uploadUrl: string,
  file: File,
  contentType: string,
  onProgress: (pct: number) => void,
  fetchImpl: typeof fetch,
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

function uploadWithXhr(
  uploadUrl: string,
  file: File,
  contentType: string,
  onProgress: (pct: number) => void,
): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("PUT", uploadUrl);
    xhr.setRequestHeader("Content-Type", contentType);

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) {
        onProgress(Math.round((event.loaded / event.total) * 100));
      } else {
        onProgress(0);
      }
    };

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        onProgress(100);
        resolve();
        return;
      }
      reject(new Error(`S3 upload failed: ${xhr.status}`));
    };

    xhr.onerror = () => reject(new Error("S3 upload failed: network error"));
    xhr.onabort = () => reject(new Error("S3 upload failed: aborted"));

    onProgress(0);
    xhr.send(file);
  });
}
