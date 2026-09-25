<script setup>
/**
 * One problem, as ErrorExplainer words it: the cause, what to do, the button
 * that does it, and the server's own text folded underneath for the
 * provider's support.
 *
 * `compact` folds it to its title line (the phone list); `dismissible` adds
 * "Ausblenden" for notices that only need to be read once.
 */
import { ref } from 'vue';
import { Alert, Button } from '@statamic/cms/ui';

const props = defineProps({
    problem: { type: Object, required: true },
    variant: { type: String, default: 'error' },
    compact: { type: Boolean, default: false },
    dismissible: { type: Boolean, default: false },
});

defineEmits(['dismiss']);

const open = ref(!props.compact);
const showDetail = ref(false);
</script>

<template>
    <Alert :variant="variant" :data-inbox-problem="problem.code || variant">
        <div class="flex items-start justify-between gap-3">
            <p class="font-medium">{{ problem.title }}</p>
            <Button
                v-if="compact"
                size="xs"
                variant="ghost"
                :icon="open ? 'chevron-up' : 'chevron-down'"
                icon-only
                :aria-label="open ? __('Show fewer details') : __('Show more details')"
                @click="open = !open"
            />
        </div>
        <template v-if="open">
            <p v-if="problem.text">{{ problem.text }}</p>
            <slot />
            <!-- One row: the fix first, then whatever the caller adds, then the server's text. -->
            <div v-if="problem.action || dismissible || problem.detail || $slots.actions" class="mt-3 flex flex-wrap items-center gap-2">
                <Button
                    v-if="problem.action"
                    size="sm"
                    :href="problem.action.url"
                    :text="problem.action.label"
                    data-inbox-problem-action
                />
                <slot name="actions" />
                <Button v-if="dismissible" size="sm" variant="ghost" :text="__('Hide')" data-inbox-dismiss @click="$emit('dismiss')" />
                <Button
                    v-if="problem.detail"
                    size="sm"
                    variant="ghost"
                    :text="showDetail ? __('Hide server message') : __('Server message')"
                    data-inbox-problem-detail-toggle
                    @click="showDetail = !showDetail"
                />
            </div>
            <p v-if="showDetail && problem.detail" class="mt-2 font-mono text-xs break-words opacity-80" data-inbox-problem-detail>{{ problem.detail }}</p>
        </template>
    </Alert>
</template>
