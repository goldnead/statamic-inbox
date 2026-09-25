/**
 * Vitest bootstrap, after statamic-automations/tests/js/setup.js.
 *
 * `@statamic/cms/ui` and `@statamic/cms/inertia` destructure
 * `window.__STATAMIC__`, which only the Control Panel installs. This puts a
 * stub there before any test module is imported: every component is a
 * `<div data-stub="Name" data-attr-…>` that mirrors its scalar props, renders
 * its default slot and forwards its listeners, so a test asserts what a page
 * handed a component, not core's markup.
 */
import { defineComponent, h, reactive } from 'vue';
import { config } from '@vue/test-utils';

const stubs = new Map();

function stubComponent(name) {
    if (!stubs.has(name)) {
        stubs.set(name, defineComponent({
            name,
            inheritAttrs: false,
            setup(_props, { attrs, slots }) {
                return () => {
                    const rendered = { 'data-stub': name };
                    const text = [];

                    for (const [key, value] of Object.entries(attrs)) {
                        if (value === null || value === undefined) continue;
                        if (key.startsWith('on') && typeof value === 'function') {
                            rendered[key] = value;
                            continue;
                        }
                        if (typeof value === 'object' || typeof value === 'function') continue;
                        rendered[key.startsWith('data-') ? key : 'data-attr-' + key.replace(/([A-Z])/g, '-$1').toLowerCase()] = String(value);
                        if (['text', 'heading', 'title', 'label'].includes(key)) text.push(h('span', String(value)));
                    }

                    return h('div', rendered, [...text, slots.default ? slots.default({}) : null]);
                };
            },
        }));
    }

    return stubs.get(name);
}

/** Passes `{ text, url, action }` to its slot, as core's does. */
const commandPaletteItemStub = defineComponent({
    name: 'CommandPaletteItem',
    inheritAttrs: false,
    setup(_props, { attrs, slots }) {
        return () => h('div', { 'data-stub': 'CommandPaletteItem' },
            slots.default ? slots.default({ text: attrs.text, url: attrs.url, action: attrs.action }) : null);
    },
});

/** Renders its content and footer only while open, as core's does. */
const confirmationModalStub = defineComponent({
    name: 'ConfirmationModal',
    inheritAttrs: false,
    setup(_props, { attrs, slots }) {
        return () => (attrs.open
            ? h('div', { 'data-stub': 'ConfirmationModal', 'data-attr-title': String(attrs.title ?? '') }, [
                h('button', { 'data-confirm': '', onClick: () => attrs.onConfirm?.() }, 'confirm'),
                slots.default ? slots.default() : null,
            ])
            : null);
    },
});

/** A Textarea/Input stub that behaves like a v-model'd field. */
function fieldStub(name, tag) {
    return defineComponent({
        name,
        inheritAttrs: false,
        props: { modelValue: { type: [String, Number], default: '' } },
        emits: ['update:modelValue'],
        setup(props, { attrs, emit }) {
            return () => h(tag, {
                'data-stub': name,
                ...Object.fromEntries(Object.entries(attrs).filter(([k, v]) => typeof v !== 'object' && typeof v !== 'function' && !k.startsWith('on'))
                    .map(([k, v]) => [k.startsWith('data-') || k === 'id' ? k : 'data-attr-' + k.replace(/([A-Z])/g, '-$1').toLowerCase(), String(v)])),
                value: props.modelValue ?? '',
                onInput: (e) => emit('update:modelValue', e.target.value),
            });
        },
    });
}

const namedStubs = {
    CommandPaletteItem: commandPaletteItemStub,
    ConfirmationModal: confirmationModalStub,
    Textarea: fieldStub('Textarea', 'textarea'),
    Input: fieldStub('Input', 'input'),
};

const componentBag = () => new Proxy({}, {
    get: (_t, prop) => (typeof prop === 'string' ? (namedStubs[prop] ?? stubComponent(prop)) : undefined),
});

const inertiaHelpers = {
    router: { reload: () => {}, visit: () => {}, get: () => {}, post: () => {} },
    useForm: (data) => reactive({ ...data, processing: false, errors: {} }),
};

globalThis.__STATAMIC__ = {
    ui: componentBag(),
    api: componentBag(),
    cms: componentBag(),
    core: componentBag(),
    inertia: new Proxy(inertiaHelpers, {
        get: (target, prop) => (prop in target ? target[prop] : (typeof prop === 'string' ? stubComponent(prop) : undefined)),
    }),
};

// The CP's translator, returning the key with `:placeholders` filled in.
const translate = (key, replacements = {}) => Object.entries(replacements ?? {}).reduce(
    (carry, [token, value]) => carry.split(`:${token}`).join(String(value)),
    String(key ?? ''),
);

config.global.mocks = { __: translate };
globalThis.__ = translate;
