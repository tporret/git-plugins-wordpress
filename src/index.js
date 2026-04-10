import { createRoot } from '@wordpress/element';
import App from './App';
import './index.css';

const container = document.getElementById('gpw-react-root');
if (container) {
  const root = createRoot(container);
  root.render(<App />);
}
