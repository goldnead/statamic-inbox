<script setup>
/**
 * One HTML mail, in a sandboxed frame.
 *
 * The sandbox never gets `allow-scripts`: nothing in a stranger's mail runs,
 * whatever slipped past the sanitiser. `allow-same-origin` without scripts only
 * lets this component read the frame's height; `allow-popups` (plus escaping
 * the sandbox) lets a link open in a new tab like in any mail program.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { buildSrcdoc } from '../support/mailBody.js';

const props = defineProps({
    html: { type: String, required: true },
    inlineImages: { type: Object, default: () => ({}) },
    loadRemote: { type: Boolean, default: false },
    showQuoted: { type: Boolean, default: false },
    title: { type: String, default: '' },
});

const emit = defineEmits(['quote']);

const frame = ref(null);
const height = ref(80);

const built = computed(() => buildSrcdoc(props.html, {
    inlineImages: props.inlineImages,
    loadRemote: props.loadRemote,
    showQuoted: props.showQuoted,
    origin: typeof window !== 'undefined' ? window.location.origin : '',
    font: typeof window !== 'undefined' && document.body ? getComputedStyle(document.body).fontFamily : '',
}));

watch(() => built.value.hasQuote, (has) => emit('quote', has), { immediate: true });

function measure() {
    const doc = frame.value?.contentDocument;
    if (!doc?.documentElement) return;
    height.value = Math.max(40, Math.ceil(doc.documentElement.scrollHeight));
}

let cleanup = [];

function loaded() {
    cleanup.forEach((fn) => fn());
    cleanup = [];
    measure();

    // Images arrive after the load event of a srcdoc frame only in some
    // browsers; measure again as each one lands.
    frame.value?.contentDocument?.querySelectorAll('img').forEach((img) => {
        img.addEventListener('load', measure);
        cleanup.push(() => img.removeEventListener('load', measure));
    });
}

onBeforeUnmount(() => cleanup.forEach((fn) => fn()));
</script>

<template>
    <iframe
        ref="frame"
        :srcdoc="built.srcdoc"
        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
        referrerpolicy="no-referrer"
        :title="title"
        :style="{ height: `${height}px` }"
        class="block w-full border-0 rounded-md"
        data-inbox-frame
        @load="loaded"
    />
</template>
