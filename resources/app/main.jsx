import { createRoot } from 'react-dom/client';
import { loadPage, RouterProvider } from './router.jsx';
import App from './App.jsx';
import './app.css';

// The first page arrives embedded in the HTML; load its code, then render.
const initial = window.OO_PAGE;
window.history.replaceState({ oo: true }, '', window.location.href);
loadPage(initial.component).then(() => {
  createRoot(document.getElementById('root')).render(
    <RouterProvider initial={initial}>
      <App />
    </RouterProvider>,
  );
});
