const API_CONFIG = {
  baseUrl: '/index.php/api/v1'
};

const API_READY =
  typeof API_CONFIG.baseUrl === 'string' &&
  API_CONFIG.baseUrl.trim().length > 0;