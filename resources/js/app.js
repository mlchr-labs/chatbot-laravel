import './echo';
import Alpine from 'alpinejs';
import knowledgeSources from './knowledge-sources';

window.Alpine = Alpine;
Alpine.data('knowledgeSources', knowledgeSources);
Alpine.start();
