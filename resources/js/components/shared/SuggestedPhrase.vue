<template>
    <span class="text-xs leading-relaxed" v-tooltip.bottom="!disabled ? 'Double-click adjacent words to expand. Double-click any word inside the anchor to shrink (left half removes from start, right half removes to end).' : null">
        <template v-if="anchorIdx >= 0">
            <span v-if="truncatedBefore" class="text-gray-400 dark:text-gray-500">… </span>
            <template v-for="(seg, i) in beforeSegments" :key="'b'+i"><span
                :class="i === adjacentBeforeIdx && !disabled
                    ? 'text-gray-500 dark:text-gray-400 cursor-pointer hover:text-blue-500 dark:hover:text-blue-300 hover:underline'
                    : 'text-gray-400 dark:text-gray-500'"
                @dblclick.prevent="i === adjacentBeforeIdx && !disabled ? expandStart() : null"
            >{{ seg.text }}</span></template><template v-for="(seg, i) in anchorSegments" :key="'a'+i"><span
                class="font-bold text-blue-600 dark:text-blue-400"
                :class="{ 'cursor-pointer hover:opacity-70': !disabled && canShrink && seg.isWord }"
                @dblclick.prevent="!disabled && canShrink && seg.isWord ? shrinkAtWord(i) : null"
            >{{ seg.text }}</span></template><template v-for="(seg, i) in afterSegments" :key="'f'+i"><span
                :class="i === adjacentAfterIdx && !disabled
                    ? 'text-gray-500 dark:text-gray-400 cursor-pointer hover:text-blue-500 dark:hover:text-blue-300 hover:underline'
                    : 'text-gray-400 dark:text-gray-500'"
                @dblclick.prevent="i === adjacentAfterIdx && !disabled ? expandEnd() : null"
            >{{ seg.text }}</span></template>
            <span v-if="truncatedAfter" class="text-gray-400 dark:text-gray-500"> …</span>
            <button
                v-if="!disabled && hasChanged"
                @click="$emit('reset')"
                class="inline-flex items-center ml-1.5 px-1 py-0.5 rounded text-xs font-medium text-amber-600 dark:text-amber-400 bg-amber-100 dark:bg-amber-900/30 hover:bg-amber-200 dark:hover:bg-amber-900/50"
                v-tooltip="'Reset to original suggestion'"
                type="button"
            >↺ undo</button>
        </template>
        <span v-else class="text-gray-400 dark:text-gray-500">{{ sentenceContext }}</span>
    </span>
</template>

<script>
export default {
    props: {
        sentenceContext: { type: String, required: true },
        anchor: { type: String, required: true },
        originalAnchor: { type: String, required: true },
        disabled: { type: Boolean, default: false },
        truncatedBefore: { type: Boolean, default: false },
        truncatedAfter: { type: Boolean, default: false },
    },

    emits: ['update:anchor', 'reset'],

    watch: {
        anchor(val) {
            if (!val || !this.hasRealWord(val) || this.sentenceContext.toLowerCase().indexOf(val.toLowerCase()) === -1) {
                this.$emit('reset');
            }
        },
    },

    computed: {
        anchorIdx() {
            if (!this.sentenceContext || !this.anchor) return -1;
            return this.sentenceContext.toLowerCase().indexOf(this.anchor.toLowerCase());
        },

        anchorEnd() {
            return this.anchorIdx + this.anchor.length;
        },

        hasChanged() {
            return this.anchor.toLowerCase() !== this.originalAnchor.toLowerCase();
        },

        beforeText() { return this.anchorIdx >= 0 ? this.sentenceContext.substring(0, this.anchorIdx) : ''; },
        anchorText() { return this.anchorIdx >= 0 ? this.sentenceContext.substring(this.anchorIdx, this.anchorEnd) : ''; },
        afterText() { return this.anchorIdx >= 0 ? this.sentenceContext.substring(this.anchorEnd) : ''; },

        beforeSegments() { return this.tokenize(this.beforeText); },
        anchorSegments() { return this.tokenize(this.anchorText); },
        afterSegments() { return this.tokenize(this.afterText); },

        adjacentBeforeIdx() {
            for (let i = this.beforeSegments.length - 1; i >= 0; i--) {
                if (this.beforeSegments[i].isWord) return i;
            }
            return -1;
        },

        adjacentAfterIdx() {
            return this.afterSegments.findIndex(s => s.isWord);
        },

        firstAnchorWordIdx() {
            return this.anchorSegments.findIndex(s => s.isWord);
        },

        lastAnchorWordIdx() {
            for (let i = this.anchorSegments.length - 1; i >= 0; i--) {
                if (this.anchorSegments[i].isWord) return i;
            }
            return -1;
        },

        canShrink() {
            return this.anchorSegments.filter(s => s.isWord).length > 1;
        },
    },

    methods: {
        /**
         * Split text into alternating word/non-word segments.
         * Words = sequences of Unicode letters/digits, including inner apostrophes (It's, don't, Laravel's).
         * Preserves ALL characters — no information lost.
         */
        tokenize(text) {
            if (!text) return [];
            const segments = [];
            const regex = /([\p{L}\p{N}]+(?:['''][\p{L}\p{N}]+)*)/gu;
            let lastIdx = 0;
            for (const match of text.matchAll(regex)) {
                if (match.index > lastIdx) {
                    segments.push({ text: text.substring(lastIdx, match.index), isWord: false });
                }
                segments.push({ text: match[0], isWord: true });
                lastIdx = match.index + match[0].length;
            }
            if (lastIdx < text.length) {
                segments.push({ text: text.substring(lastIdx), isWord: false });
            }
            return segments;
        },

        hasRealWord(text) {
            return /[\p{L}\p{N}]/u.test(text);
        },

        expandStart() {
            if (this.adjacentBeforeIdx === -1) return;
            const word = this.beforeSegments[this.adjacentBeforeIdx];
            const pos = this.beforeText.lastIndexOf(word.text);
            if (pos === -1) return;
            const newAnchor = this.sentenceContext.substring(pos, this.anchorEnd);
            if (this.hasRealWord(newAnchor)) this.$emit('update:anchor', newAnchor);
        },

        expandEnd() {
            if (this.adjacentAfterIdx === -1) return;
            const word = this.afterSegments[this.adjacentAfterIdx];
            const pos = this.afterText.indexOf(word.text);
            if (pos === -1) return;
            const newEnd = this.anchorEnd + pos + word.text.length;
            const newAnchor = this.sentenceContext.substring(this.anchorIdx, newEnd);
            if (this.hasRealWord(newAnchor)) this.$emit('update:anchor', newAnchor);
        },

        /**
         * Shrink the anchor by double-clicking any word inside it.
         * Left-half click: remove the clicked word and everything before it.
         * Right-half click (including exact middle): remove the clicked word and everything after it.
         * Example "the quick brown fox jumps" (5 words):
         *   click "the"   (idx 0, left)  → "quick brown fox jumps"
         *   click "quick" (idx 1, left)  → "brown fox jumps"
         *   click "brown" (idx 2, mid → right) → "the quick"
         *   click "jumps" (idx 4, right) → "the quick brown fox"
         */
        shrinkAtWord(segmentIdx) {
            const wordPositions = this.anchorSegments
                .map((seg, idx) => (seg.isWord ? idx : -1))
                .filter(idx => idx !== -1);

            if (wordPositions.length <= 1) return;

            const clickedWordPos = wordPositions.indexOf(segmentIdx);
            if (clickedWordPos === -1) return;

            const isLeftHalf = clickedWordPos < Math.floor(wordPositions.length / 2);

            // Slice segments directly — no text search → duplicate words handled correctly
            let newAnchor;
            if (isLeftHalf) {
                // Drop clicked word + everything before it
                const keptSegments = this.anchorSegments.slice(segmentIdx + 1);
                newAnchor = keptSegments.map(s => s.text).join('').replace(/^[^\p{L}\p{N}]+/u, '');
            } else {
                // Drop clicked word + everything after it
                const keptSegments = this.anchorSegments.slice(0, segmentIdx);
                newAnchor = keptSegments.map(s => s.text).join('').replace(/[^\p{L}\p{N}]+$/u, '');
            }

            if (!newAnchor || !this.hasRealWord(newAnchor)) return;
            if (this.sentenceContext.toLowerCase().indexOf(newAnchor.toLowerCase()) === -1) return;
            this.$emit('update:anchor', newAnchor);
        },
    },
};
</script>
