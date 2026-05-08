import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const proxyTarget = env.OPENEMR_PROXY_TARGET || 'http://localhost:8300';

  return {
    plugins: [react()],
    server: {
      port: 5174,
      host: '0.0.0.0',
      proxy: {
        '/apis': {
          target: proxyTarget,
          changeOrigin: true,
          secure: false
        },
        '/oauth2': {
          target: proxyTarget,
          changeOrigin: true,
          secure: false
        },
        '/interface': {
          target: proxyTarget,
          changeOrigin: true,
          secure: false
        }
      }
    },
    preview: {
      port: 4174,
      host: '0.0.0.0'
    }
  };
});
