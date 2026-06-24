import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import './styles/app.css';

// inline-скрипты в шаблонах зовут глобальный bootstrap.* (Tooltip/Modal/Offcanvas/Alert/Collapse) —
// пробрасываем модуль в window, иначе ReferenceError: bootstrap is not defined.
window.bootstrap = require('bootstrap');
