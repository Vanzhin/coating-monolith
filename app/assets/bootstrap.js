import { startStimulusApp } from '@symfony/stimulus-bridge';

// Registers Stimulus controllers from controllers.json and in the controllers/ directory
export const app = startStimulusApp(require.context(
    '@symfony/stimulus-bridge/lazy-controller-loader!./controllers',
    true,
    /\.[jt]sx?$/
));
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);

// Глобальный доступ к Stimulus-приложению — используется surface_treatment_modal_controller
// для вызова selectItem() на async-typeahead после inline-создания нового treatment.
window.Stimulus = app;
