const withLocalNetwork = require('./plugins/with-local-network');

module.exports = ({ config }) => {
  const variant = process.env.APP_VARIANT || process.env.EAS_BUILD_PROFILE;
  const api = process.env.EXPO_PUBLIC_API_URL;
  if (variant) {
    if (!api) throw new Error('Defina EXPO_PUBLIC_API_URL para este ambiente.');
    const url = new URL(api);
    if (url.username || url.password || url.search || url.hash || url.pathname !== '/api/v1') {
      throw new Error('A API deve ser uma URL sem credenciais terminada em /api/v1.');
    }
    if (variant === 'preview' && api !== 'https://alugfacil.net.br/api/v1') {
      throw new Error('Preview externo exige a API HTTPS oficial validada antes do build.');
    }
    const local = variant === 'development' && url.hostname === '192.168.15.6';
    if (url.protocol !== 'https:' && !(local && url.protocol === 'http:')) {
      throw new Error('HTTPS obrigatório; HTTP permitido somente para a API LAN de development.');
    }
    if (variant === 'production' && (url.hostname === 'localhost' || /^[\d.]+$/.test(url.hostname) || url.hostname.endsWith('.example.com'))) {
      throw new Error('Configure o domínio HTTPS oficial antes de gerar production.');
    }
    return withLocalNetwork(config, { host: local && url.protocol === 'http:' ? url.hostname : null });
  }
  return config;
};
