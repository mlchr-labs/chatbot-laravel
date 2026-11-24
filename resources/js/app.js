import './echo';
import Alpine from 'alpinejs';
import knowledgeSources from './knowledge-sources';
import modelPicker from './model-picker';

window.Alpine = Alpine;
Alpine.data('knowledgeSources', knowledgeSources);
Alpine.data('modelPicker', modelPicker);
Alpine.start();
