import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './index.css';

// After a redeploy the running tab may still reference hashed chunks that no longer exist.
// Reload once (guarded) so the fresh index.html and its chunks are picked up instead of a blank route.
const RELOAD_KEY = 'facultylens_chunk_reload';
window.addEventListener('vite:preloadError', (event) => {
  event.preventDefault();
  if (sessionStorage.getItem(RELOAD_KEY) === location.href) return;
  sessionStorage.setItem(RELOAD_KEY, location.href);
  location.reload();
});
window.addEventListener('load', () => sessionStorage.removeItem(RELOAD_KEY));

ReactDOM.createRoot(document.getElementById('root') as HTMLElement).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);

