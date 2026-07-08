export const env = {
  storage: import.meta.env.VITE_STORAGE_URL,
  api: import.meta.env.VITE_API_BASE_URL || import.meta.env.VITE_API_URL,
};
